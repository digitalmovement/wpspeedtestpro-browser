<?php
/**
 * Debug Analyzer class for troubleshooting file processing issues
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSTB_Debug_Analyzer {
    
    public function __construct() {
        add_action('wp_ajax_wpstb_debug_search_file', array($this, 'ajax_debug_search_file'));
        add_action('wp_ajax_wpstb_debug_analyze_file', array($this, 'ajax_debug_analyze_file'));
        add_action('wp_ajax_wpstb_debug_list_files', array($this, 'ajax_debug_list_files'));
        add_action('wp_ajax_wpstb_debug_database_stats', array($this, 'ajax_debug_database_stats'));
    }
    
    /**
     * Search for files in S3 bucket
     */
    public function ajax_debug_search_file() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $search_term = sanitize_text_field($_POST['search_term'] ?? '');
            $file_type = sanitize_text_field($_POST['file_type'] ?? 'all'); // all, bug_report, diagnostic
            
            $s3 = new WPSTB_S3_Connector();
            
            // Use enhanced discovery approach
            $root_objects = $s3->list_objects('', 2000);
            
            // Explicitly scan known directories
            $common_directories = array('bug-reports', 'reports', 'data', 'diagnostics');
            $all_objects = $root_objects;
            $existing_keys = array_column($all_objects, 'Key');
            
            foreach ($common_directories as $dir) {
                try {
                    $dir_objects = $s3->list_objects($dir . '/', 1000);
                    foreach ($dir_objects as $dir_obj) {
                        if (!in_array($dir_obj['Key'], $existing_keys)) {
                            $all_objects[] = $dir_obj;
                            $existing_keys[] = $dir_obj['Key'];
                        }
                    }
                } catch (Exception $e) {
                    // Ignore if directory doesn't exist
                }
            }
            
            $filtered_files = array();
            
            foreach ($all_objects as $object) {
                $key = $object['Key'];
                
                // Only process JSON files
                if (!preg_match('/\.json$/i', $key)) {
                    continue;
                }
                
                // Apply search filter
                if (!empty($search_term) && stripos($key, $search_term) === false) {
                    continue;
                }
                
                // Determine file type
                $is_bug_report = $this->is_bug_report_file($key);
                $detected_type = $is_bug_report ? 'bug_report' : 'diagnostic';
                
                // Apply type filter
                if ($file_type !== 'all' && $file_type !== $detected_type) {
                    continue;
                }
                
                // Extract additional info
                $site_hash = '';
                $timestamp = '';
                if (!$is_bug_report && preg_match('/([a-f0-9]{32,64})\/(\d+)\.json$/i', $key, $matches)) {
                    $site_hash = $matches[1];
                    $timestamp = $matches[2];
                }
                
                $filtered_files[] = array(
                    'key' => $key,
                    'size' => $object['Size'],
                    'last_modified' => $object['LastModified'],
                    'type' => $detected_type,
                    'site_hash' => $site_hash,
                    'timestamp' => $timestamp,
                    'is_processed' => WPSTB_Database::is_file_processed($key)
                );
            }
            
            // Sort by last modified (newest first)
            usort($filtered_files, function($a, $b) {
                return strtotime($b['last_modified']) - strtotime($a['last_modified']);
            });
            
            wp_send_json_success(array(
                'files' => array_slice($filtered_files, 0, 50), // Limit to 50 results
                'total_found' => count($filtered_files),
                'search_term' => $search_term,
                'file_type' => $file_type
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Search failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Analyze a specific file in detail
     */
    public function ajax_debug_analyze_file() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $file_key = sanitize_text_field($_POST['file_key'] ?? '');
            
            if (empty($file_key)) {
                wp_send_json_error('File key is required');
                return;
            }
            
            $s3 = new WPSTB_S3_Connector();
            
            // Get file content
            $raw_content = $s3->get_object($file_key);
            $parsed_data = json_decode($raw_content, true);
            
            if ($parsed_data === null) {
                wp_send_json_error('Invalid JSON in file: ' . json_last_error_msg());
                return;
            }
            
            // Analyze file structure
            $analysis = array(
                'file_info' => array(
                    'key' => $file_key,
                    'size' => strlen($raw_content),
                    'is_processed' => WPSTB_Database::is_file_processed($file_key)
                ),
                'raw_content' => $raw_content,
                'parsed_data' => $parsed_data,
                'structure_analysis' => $this->analyze_data_structure($parsed_data),
                'processing_simulation' => array()
            );
            
            // Determine file type and simulate processing
            $is_bug_report = $this->is_bug_report_file($file_key);
            
            if ($is_bug_report) {
                $analysis['file_type'] = 'bug_report';
                $analysis['processing_simulation'] = $this->simulate_bug_report_processing($file_key, $parsed_data);
            } else {
                $analysis['file_type'] = 'diagnostic';
                $analysis['processing_simulation'] = $this->simulate_diagnostic_processing($file_key, $parsed_data);
            }
            
            // Check database for existing records
            $analysis['database_check'] = $this->check_database_records($file_key, $parsed_data, $is_bug_report);
            
            wp_send_json_success($analysis);
            
        } catch (Exception $e) {
            wp_send_json_error('Analysis failed: ' . $e->getMessage());
        }
    }
    
    /**
     * List files with processing status
     */
    public function ajax_debug_list_files() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $page = intval($_POST['page'] ?? 1);
            $per_page = 20;
            
            $s3 = new WPSTB_S3_Connector();
            
            // Use enhanced discovery approach
            $root_objects = $s3->list_objects('', 2000);
            
            // Explicitly scan known directories
            $common_directories = array('bug-reports', 'reports', 'data', 'diagnostics');
            $all_objects = $root_objects;
            $existing_keys = array_column($all_objects, 'Key');
            
            foreach ($common_directories as $dir) {
                try {
                    $dir_objects = $s3->list_objects($dir . '/', 1000);
                    foreach ($dir_objects as $dir_obj) {
                        if (!in_array($dir_obj['Key'], $existing_keys)) {
                            $all_objects[] = $dir_obj;
                            $existing_keys[] = $dir_obj['Key'];
                        }
                    }
                } catch (Exception $e) {
                    // Ignore if directory doesn't exist
                }
            }
            
            // Filter JSON files only
            $json_files = array_filter($all_objects, function($obj) {
                return preg_match('/\.json$/i', $obj['Key']);
            });
            
            // Add processing status
            $files_with_status = array();
            foreach ($json_files as $object) {
                $key = $object['Key'];
                $is_bug_report = $this->is_bug_report_file($key);
                
                $files_with_status[] = array(
                    'key' => $key,
                    'size' => $object['Size'],
                    'last_modified' => $object['LastModified'],
                    'type' => $is_bug_report ? 'bug_report' : 'diagnostic',
                    'is_processed' => WPSTB_Database::is_file_processed($key)
                );
            }
            
            // Sort by last modified
            usort($files_with_status, function($a, $b) {
                return strtotime($b['last_modified']) - strtotime($a['last_modified']);
            });
            
            // Paginate
            $offset = ($page - 1) * $per_page;
            $paginated_files = array_slice($files_with_status, $offset, $per_page);
            
            wp_send_json_success(array(
                'files' => $paginated_files,
                'total_files' => count($files_with_status),
                'current_page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil(count($files_with_status) / $per_page)
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('List failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get database statistics
     */
    public function ajax_debug_database_stats() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            global $wpdb;
            
            $stats = array(
                'bug_reports' => array(),
                'diagnostic_data' => array(),
                'processed_files' => array(),
                'site_plugins' => array()
            );
            
            // Bug reports stats
            $bug_reports_table = $wpdb->prefix . 'wpstb_bug_reports';
            $stats['bug_reports'] = array(
                'total' => $wpdb->get_var("SELECT COUNT(*) FROM $bug_reports_table"),
                'unique_sites' => $wpdb->get_var("SELECT COUNT(DISTINCT site_key) FROM $bug_reports_table"),
                'recent' => $wpdb->get_results("SELECT site_key, site_url, created_at FROM $bug_reports_table ORDER BY created_at DESC LIMIT 10")
            );
            
            // Diagnostic data stats
            $diagnostic_table = $wpdb->prefix . 'wpstb_diagnostic_data';
            $stats['diagnostic_data'] = array(
                'total' => $wpdb->get_var("SELECT COUNT(*) FROM $diagnostic_table"),
                'unique_sites' => $wpdb->get_var("SELECT COUNT(DISTINCT site_key) FROM $diagnostic_table"),
                'unique_urls' => $wpdb->get_var("SELECT COUNT(DISTINCT site_url) FROM $diagnostic_table WHERE site_url IS NOT NULL AND site_url != ''"),
                'recent' => $wpdb->get_results("SELECT site_key, site_url, file_path, processed_at FROM $diagnostic_table ORDER BY processed_at DESC LIMIT 10"),
                'sample_urls' => $wpdb->get_results("SELECT DISTINCT site_url FROM $diagnostic_table WHERE site_url IS NOT NULL AND site_url != '' ORDER BY site_url LIMIT 20")
            );
            
            // Processed files stats
            $processed_table = $wpdb->prefix . 'wpstb_processed_files';
            $stats['processed_files'] = array(
                'total' => $wpdb->get_var("SELECT COUNT(*) FROM $processed_table"),
                'recent' => $wpdb->get_results("SELECT file_path, processed_at FROM $processed_table ORDER BY processed_at DESC LIMIT 10")
            );
            
            // Site plugins stats
            $plugins_table = $wpdb->prefix . 'wpstb_site_plugins';
            $stats['site_plugins'] = array(
                'total' => $wpdb->get_var("SELECT COUNT(*) FROM $plugins_table"),
                'unique_plugins' => $wpdb->get_var("SELECT COUNT(DISTINCT plugin_name) FROM $plugins_table")
            );
            
            wp_send_json_success($stats);
            
        } catch (Exception $e) {
            wp_send_json_error('Database stats failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if file is a bug report
     */
    private function is_bug_report_file($key) {
        return strpos($key, 'bug-reports/') !== false || 
               strpos($key, 'bug-reports') === 0 || 
               strpos(strtolower($key), 'bug-report') !== false;
    }
    
    /**
     * Analyze data structure
     */
    private function analyze_data_structure($data) {
        $analysis = array(
            'top_level_keys' => array_keys($data),
            'structure' => array(),
            'potential_issues' => array()
        );
        
        foreach ($data as $key => $value) {
            $analysis['structure'][$key] = array(
                'type' => gettype($value),
                'value' => is_array($value) ? '[' . count($value) . ' items]' : (is_string($value) ? substr($value, 0, 100) : $value)
            );
            
            if (is_array($value)) {
                $analysis['structure'][$key]['sub_keys'] = array_keys($value);
            }
        }
        
        // Check for common issues
        if (!isset($data['siteInfo']) && !isset($data['environment'])) {
            $analysis['potential_issues'][] = 'Missing siteInfo or environment data';
        }
        
        if (!isset($data['siteKey']) && !isset($data['siteInfo']['siteKey'])) {
            $analysis['potential_issues'][] = 'Missing siteKey';
        }
        
        return $analysis;
    }
    
    /**
     * Simulate bug report processing
     */
    private function simulate_bug_report_processing($file_path, $data) {
        $simulation = array(
            'extracted_data' => array(),
            'issues' => array(),
            'would_insert' => true
        );
        
        $extracted = array(
            'site_key' => $data['siteKey'] ?? '',
            'report_id' => basename($file_path, '.json'),
            'email' => $data['report']['email'] ?? '',
            'message' => $data['report']['message'] ?? '',
            'site_url' => $data['siteInfo']['site_url'] ?? '',
            'wp_version' => $data['siteInfo']['wp_version'] ?? '',
            'php_version' => $data['siteInfo']['php_version'] ?? ''
        );
        
        $simulation['extracted_data'] = $extracted;
        
        // Check for issues
        if (empty($extracted['site_key'])) {
            $simulation['issues'][] = 'Missing site_key';
        }
        if (empty($extracted['email'])) {
            $simulation['issues'][] = 'Missing email';
        }
        if (empty($extracted['site_url'])) {
            $simulation['issues'][] = 'Missing site_url';
        }
        
        return $simulation;
    }
    
    /**
     * Simulate diagnostic processing
     */
    private function simulate_diagnostic_processing($file_path, $data) {
        $simulation = array(
            'extracted_data' => array(),
            'issues' => array(),
            'would_insert' => true
        );
        
        // Extract site key
        $site_key = $data['siteInfo']['siteKey'] ?? $data['siteKey'] ?? '';
        if (empty($site_key)) {
            if (preg_match('/([a-f0-9]{32,64})\/\d+\.json$/i', $file_path, $matches)) {
                $site_key = $matches[1];
            }
        }
        
        // Extract site URL
        $site_url = '';
        if (isset($data['clientInfo']['userAgent'])) {
            if (preg_match('/WordPress\/[\d\.]+;\s*(https?:\/\/[^\s]+)/', $data['clientInfo']['userAgent'], $matches)) {
                $site_url = $matches[1];
            }
        }
        
        $extracted = array(
            'site_key' => $site_key,
            'file_path' => $file_path,
            'site_url' => $site_url,
            'wp_version' => $data['environment']['wp_version'] ?? '',
            'php_version' => $data['environment']['php_version'] ?? '',
            'country' => $data['clientInfo']['country'] ?? '',
            'city' => $data['clientInfo']['city'] ?? '',
            'hosting_provider_id' => $data['environment']['hosting_provider_id'] ?? null
        );
        
        $simulation['extracted_data'] = $extracted;
        
        // Check for issues
        if (empty($extracted['site_key'])) {
            $simulation['issues'][] = 'Missing site_key';
        }
        if (empty($extracted['site_url'])) {
            $simulation['issues'][] = 'Missing site_url (could not extract from userAgent)';
        }
        if (empty($extracted['wp_version'])) {
            $simulation['issues'][] = 'Missing wp_version';
        }
        
        return $simulation;
    }
    
    /**
     * Check database for existing records
     */
    private function check_database_records($file_path, $data, $is_bug_report) {
        global $wpdb;
        
        $check = array(
            'file_processed' => WPSTB_Database::is_file_processed($file_path),
            'records_found' => array()
        );
        
        if ($is_bug_report) {
            $table = $wpdb->prefix . 'wpstb_bug_reports';
            $site_key = $data['siteKey'] ?? '';
            $report_id = basename($file_path, '.json');
            
            if (!empty($site_key)) {
                $records = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $table WHERE site_key = %s AND report_id = %s",
                    $site_key,
                    $report_id
                ));
                $check['records_found'] = $records;
            }
        } else {
            $table = $wpdb->prefix . 'wpstb_diagnostic_data';
            
            // Check by file path
            $records = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE file_path = %s",
                $file_path
            ));
            $check['records_found'] = $records;
        }
        
        return $check;
    }
} 