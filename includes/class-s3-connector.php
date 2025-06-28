<?php
/**
 * S3 Connector class for managing S3 bucket operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSTB_S3_Connector {
    
    private $endpoint;
    private $access_key;
    private $secret_key;
    private $bucket;
    
    public function __construct() {
        $this->endpoint = get_option('wpstb_s3_endpoint', '');
        $this->access_key = get_option('wpstb_s3_access_key', '');
        $this->secret_key = get_option('wpstb_s3_secret_key', '');
        $this->bucket = get_option('wpstb_s3_bucket', '');
    }
    
    /**
     * Test S3 connection
     */
    public function test_connection() {
        if (empty($this->endpoint) || empty($this->access_key) || empty($this->secret_key)) {
            return array('success' => false, 'message' => 'S3 credentials not configured');
        }
        
        try {
            $this->list_objects('', 1);
            return array('success' => true, 'message' => 'Connection successful');
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * List objects in S3 bucket
     */
    public function list_objects($prefix = '', $max_keys = 1000) {
        $url = $this->endpoint . '/' . $this->bucket . '/';
        
        $params = array(
            'list-type' => '2',
            'max-keys' => $max_keys
        );
        
        if (!empty($prefix)) {
            $params['prefix'] = $prefix;
        }
        
        $response = wp_remote_get($url . '?' . http_build_query($params), array('timeout' => 30));
        
        if (is_wp_error($response)) {
            throw new Exception('HTTP request failed');
        }
        
        $xml = simplexml_load_string(wp_remote_retrieve_body($response));
        
        if ($xml === false) {
            throw new Exception('Failed to parse XML response');
        }
        
        $objects = array();
        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $content) {
                $objects[] = array(
                    'Key' => (string)$content->Key,
                    'Size' => (int)$content->Size,
                    'LastModified' => (string)$content->LastModified
                );
            }
        }
        
        return $objects;
    }
    
    /**
     * Get object from S3
     */
    public function get_object($key) {
        $url = $this->endpoint . '/' . $this->bucket . '/' . $key;
        
        $response = wp_remote_get($url, array('timeout' => 30));
        
        if (is_wp_error($response)) {
            throw new Exception('HTTP request failed');
        }
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            throw new Exception('S3 request failed');
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * Scan S3 bucket for new files
     */
    public function scan_bucket() {
        $results = array(
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'new_bug_reports' => 0,
            'new_diagnostic_files' => 0
        );
        
        try {
            // Get all objects
            $objects = $this->list_objects();
            
            foreach ($objects as $object) {
                $key = $object['Key'];
                
                // Skip if already processed or not a JSON file
                if (WPSTB_Database::is_file_processed($key) || !preg_match('/\.json$/', $key)) {
                    $results['skipped']++;
                    continue;
                }
                
                try {
                    // Get file content
                    $content = $this->get_object($key);
                    $data = json_decode($content, true);
                    
                    if ($data === null) {
                        $results['errors']++;
                        continue;
                    }
                    
                    // Process based on file location
                    if (strpos($key, 'bug-reports/') !== false) {
                        $this->process_bug_report($key, $data);
                        $results['new_bug_reports']++;
                    } else {
                        $this->process_diagnostic_data($key, $data);
                        $results['new_diagnostic_files']++;
                    }
                    
                    // Mark as processed
                    WPSTB_Database::mark_file_processed($key, md5($content));
                    $results['processed']++;
                    
                } catch (Exception $e) {
                    error_log('WPSTB: Error processing file ' . $key . ': ' . $e->getMessage());
                    $results['errors']++;
                }
            }
            
            // Update last scan time
            update_option('wpstb_last_scan', current_time('mysql'));
            
        } catch (Exception $e) {
            throw new Exception('Bucket scan failed: ' . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * Process bug report data
     */
    private function process_bug_report($file_path, $data) {
        WPSTB_Database::insert_bug_report(array(
            'site_key' => $data['siteKey'] ?? '',
            'report_id' => basename($file_path, '.json'),
            'email' => $data['report']['email'] ?? '',
            'message' => $data['report']['message'] ?? '',
            'priority' => $data['report']['priority'] ?? '',
            'severity' => $data['report']['severity'] ?? '',
            'wp_version' => $data['siteInfo']['wp_version'] ?? '',
            'site_url' => $data['siteInfo']['site_url'] ?? '',
            'timestamp' => $this->parse_timestamp($data['timestamp'] ?? '')
        ));
    }
    
    /**
     * Process diagnostic data
     */
    private function process_diagnostic_data($file_path, $data) {
        // Extract site URL from user agent
        $site_url = $this->extract_site_url($data['clientInfo']['userAgent'] ?? '');
        
        $diagnostic_id = WPSTB_Database::insert_diagnostic_data(array(
            'site_key' => $data['siteInfo']['siteKey'] ?? '',
            'file_path' => $file_path,
            'site_url' => $site_url,
            'wp_version' => $data['environment']['wp_version'] ?? '',
            'php_version' => $data['environment']['php_version'] ?? '',
            'country' => $data['clientInfo']['country'] ?? '',
            'timestamp' => $this->parse_timestamp($data['timestamp'] ?? '')
        ));
        
        // Process plugins
        if (!empty($data['environment']['active_plugins']) && $diagnostic_id) {
            foreach ($data['environment']['active_plugins'] as $plugin) {
                WPSTB_Database::insert_site_plugin($diagnostic_id, $plugin['name'] ?? '', $plugin['version'] ?? '');
            }
        }
    }
    
    /**
     * Extract site URL from user agent
     */
    private function extract_site_url($user_agent) {
        if (preg_match('/WordPress\/[\d\.]+;\s*(https?:\/\/[^\s]+)/', $user_agent, $matches)) {
            return $matches[1];
        }
        return '';
    }
    
    /**
     * Parse timestamp to MySQL format
     */
    private function parse_timestamp($timestamp) {
        if (empty($timestamp)) {
            return null;
        }
        
        $date = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $timestamp);
        if ($date === false) {
            $date = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $timestamp);
        }
        
        return $date ? $date->format('Y-m-d H:i:s') : null;
    }
} 