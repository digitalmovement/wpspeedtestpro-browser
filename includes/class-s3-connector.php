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
    private $region;
    
    public function __construct() {
        $this->endpoint = get_option('wpstb_s3_endpoint', '');
        $this->access_key = get_option('wpstb_s3_access_key', '');
        $this->secret_key = get_option('wpstb_s3_secret_key', '');
        $this->bucket = get_option('wpstb_s3_bucket', '');
        $this->region = 'auto'; // Default region for most S3-compatible services
    }
    
    /**
     * Test S3 connection with detailed diagnostics
     */
    public function test_connection() {
        WPSTB_Utilities::log('Starting S3 connection test...');
        
        // Check all required credentials
        $missing = array();
        if (empty($this->endpoint)) $missing[] = 'endpoint';
        if (empty($this->access_key)) $missing[] = 'access key';
        if (empty($this->secret_key)) $missing[] = 'secret key';
        if (empty($this->bucket)) $missing[] = 'bucket name';
        
        if (!empty($missing)) {
            $message = 'Missing S3 credentials: ' . implode(', ', $missing);
            WPSTB_Utilities::log($message, 'error');
            return array('success' => false, 'message' => $message);
        }
        
        // Log credential information for debugging (without exposing the actual keys)
        WPSTB_Utilities::log('S3 Configuration - Endpoint: ' . $this->endpoint);
        WPSTB_Utilities::log('S3 Configuration - Access Key Length: ' . strlen($this->access_key));
        WPSTB_Utilities::log('S3 Configuration - Secret Key Length: ' . strlen($this->secret_key));
        WPSTB_Utilities::log('S3 Configuration - Bucket: ' . $this->bucket);
        WPSTB_Utilities::log('S3 Configuration - Region: ' . $this->region);
        
        // Validate key lengths based on service type
        $validation_result = $this->validate_credentials();
        if (!$validation_result['valid']) {
            WPSTB_Utilities::log($validation_result['message'], 'error');
            return array('success' => false, 'message' => $validation_result['message']);
        }
        
        try {
            WPSTB_Utilities::log('Testing connection to: ' . $this->endpoint . '/' . $this->bucket);
            
            // For debugging, let's try a simple request first
            if (strpos($this->endpoint, 'r2.cloudflarestorage.com') !== false) {
                WPSTB_Utilities::log('Detected Cloudflare R2, trying alternative authentication...');
                $result = $this->test_cloudflare_r2_auth();
                if ($result['success']) {
                    return $result;
                }
                WPSTB_Utilities::log('Alternative auth failed: ' . $result['message']);
            }
            
            $objects = $this->list_objects('', 1);
            $count = count($objects);
            $message = "Connection successful! Found {$count} object(s) in bucket.";
            WPSTB_Utilities::log($message);
            return array('success' => true, 'message' => $message);
        } catch (Exception $e) {
            $error_message = 'Connection failed: ' . $e->getMessage();
            WPSTB_Utilities::log($error_message, 'error');
            
            // Add detailed error info for debugging
            $detailed_message = $error_message;
            $detailed_message .= "\n\nDEBUG INFO:";
            $detailed_message .= "\n- Endpoint: " . $this->endpoint;
            $detailed_message .= "\n- Bucket: " . $this->bucket;
            $detailed_message .= "\n- Region: " . $this->region;
            $detailed_message .= "\n- Access Key Length: " . strlen($this->access_key);
            $detailed_message .= "\n- Secret Key Length: " . strlen($this->secret_key);
            $detailed_message .= "\n\nTip: Enable WordPress debug logging (WP_DEBUG_LOG = true) to see detailed authentication logs.";
            
            return array('success' => false, 'message' => $detailed_message);
        }
    }
    
    /**
     * List objects in S3 bucket with proper authentication and pagination support
     */
    public function list_objects($prefix = '', $max_keys = 1000) {
        $all_objects = array();
        $continuation_token = null;
        $page_count = 0;
        $max_pages = 100; // Increased from 10 to handle large buckets with many directories
        $use_pagination = true; // Flag to control pagination
        
        WPSTB_Utilities::log('Starting paginated S3 list request with prefix: "' . $prefix . '" and max_keys: ' . $max_keys);
        
        do {
            $page_count++;
            $path = '/' . $this->bucket . '/';
            $query_params = array(
                'list-type' => '2',
                'max-keys' => min($max_keys, 1000) // AWS limits to 1000 per request
            );
            
            if (!empty($prefix)) {
                $query_params['prefix'] = $prefix;
            }
            
            if ($continuation_token !== null) {
                $query_params['continuation-token'] = $continuation_token;
            }
            
            // Build query string with proper encoding for AWS signature
            $query_parts = array();
            foreach ($query_params as $key => $value) {
                $query_parts[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
            $query_string = implode('&', $query_parts);
            
            $url = $this->endpoint . $path . '?' . $query_string;
            
            WPSTB_Utilities::log('Making S3 list request (page ' . $page_count . ') to: ' . $url);
            
            // Generate signed headers
            $headers = $this->get_signed_headers('GET', $path, $query_string, '');
            
            $response = wp_remote_get($url, array(
                'headers' => $headers,
                'timeout' => 30,
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                $error = 'HTTP request failed: ' . $response->get_error_message();
                WPSTB_Utilities::log($error, 'error');
                throw new Exception($error);
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            WPSTB_Utilities::log('S3 response code: ' . $response_code);
            
            if ($response_code !== 200) {
                WPSTB_Utilities::log('S3 error response: ' . substr($body, 0, 500), 'error');
                
                // If pagination fails, try without pagination on first page
                if ($page_count === 1 && $continuation_token === null) {
                    throw new Exception("S3 request failed with status {$response_code}. Response: " . substr($body, 0, 200));
                } else {
                    // Pagination might be causing issues, stop here and return what we have
                    WPSTB_Utilities::log('Pagination error on page ' . $page_count . ', returning ' . count($all_objects) . ' objects collected so far');
                    $use_pagination = false;
                    break;
                }
            }
            
            $xml = simplexml_load_string($body);
            
            if ($xml === false) {
                WPSTB_Utilities::log('Failed to parse XML response: ' . substr($body, 0, 500), 'error');
                throw new Exception('Failed to parse XML response from S3');
            }
            
            // Parse objects from this page
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $content) {
                    $all_objects[] = array(
                        'Key' => (string)$content->Key,
                        'Size' => (int)$content->Size,
                        'LastModified' => (string)$content->LastModified
                    );
                }
            }
            
            // Check if there are more pages
            $is_truncated = isset($xml->IsTruncated) && (string)$xml->IsTruncated === 'true';
            $continuation_token = isset($xml->NextContinuationToken) ? (string)$xml->NextContinuationToken : null;
            
            $objects_on_page = isset($xml->Contents) ? count($xml->Contents) : 0;
            WPSTB_Utilities::log('Page ' . $page_count . ' retrieved ' . $objects_on_page . ' objects. Total so far: ' . count($all_objects) . '. Is truncated: ' . ($is_truncated ? 'yes' : 'no'));
            
            // Break if we've reached the desired number of objects
            if (count($all_objects) >= $max_keys) {
                WPSTB_Utilities::log('Reached max_keys limit of ' . $max_keys . ', stopping pagination');
                $all_objects = array_slice($all_objects, 0, $max_keys);
                break;
            }
            
            // Safety check to prevent infinite loops
            if ($page_count >= $max_pages) {
                WPSTB_Utilities::log('Reached maximum page limit of ' . $max_pages . ', stopping pagination');
                break;
            }
            
        } while ($is_truncated && $continuation_token !== null && $use_pagination);
        
        WPSTB_Utilities::log('Successfully retrieved ' . count($all_objects) . ' total objects from S3 (across ' . $page_count . ' pages)');
        return $all_objects;
    }
    
    /**
     * Get object from S3 with proper authentication
     */
    public function get_object($key) {
        $path = '/' . $this->bucket . '/' . $key;
        $url = $this->endpoint . $path;
        
        WPSTB_Utilities::log('Getting S3 object: ' . $key);
        
        $headers = $this->get_signed_headers('GET', $path, '', '');
        
        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('HTTP request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            throw new Exception("S3 get object failed with status {$response_code}. Response: " . substr($body, 0, 200));
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * Scan S3 bucket for new files with detailed logging - Directory-based approach
     */
    public function scan_bucket() {
        WPSTB_Utilities::log('========================================');
        WPSTB_Utilities::log('Starting S3 bucket scan (directory-based approach)...');
        WPSTB_Utilities::log('========================================');
        
        $results = array(
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'new_bug_reports' => 0,
            'new_diagnostic_files' => 0,
            'total_objects' => 0,
            'total_directories' => 0,
            'processed_directories' => 0,
            'root_objects' => 0,
            'bug_report_objects' => 0
        );
        
        try {
            // Get all objects from root with increased limit to ensure we get everything
            WPSTB_Utilities::log('Step 1: Fetching all objects from root directory...');
            $objects = $this->list_objects('', 100000); // Greatly increased limit to get ALL objects
            $results['root_objects'] = count($objects);
            WPSTB_Utilities::log('Found ' . $results['root_objects'] . ' objects in root directory');
            
            // Log first 10 object keys for debugging
            WPSTB_Utilities::log('Sample of root objects found:');
            $sample_count = min(10, count($objects));
            for ($i = 0; $i < $sample_count; $i++) {
                WPSTB_Utilities::log('  - ' . $objects[$i]['Key']);
            }
            
            // Simple approach: If initial scan seems incomplete, try multiple smaller scans
            WPSTB_Utilities::log('=== CHECKING SCAN COMPLETENESS ===');
            
            // The initial scan might be hitting limits. Let's check if we got a suspiciously round number
            if (count($objects) == 1000 || count($objects) == 2000 || count($objects) == 5000 || count($objects) == 10000) {
                WPSTB_Utilities::log('Initial scan returned exactly ' . count($objects) . ' objects - likely hit a limit');
                WPSTB_Utilities::log('Will attempt to discover more directories through incremental scanning');
            }
            
            // Extract all directories we found so far
            $directories_found = array();
            foreach ($objects as $obj) {
                if (strpos($obj['Key'], '/') !== false) {
                    $dir = explode('/', $obj['Key'])[0];
                    if (!in_array($dir, $directories_found)) {
                        $directories_found[] = $dir;
                    }
                }
            }
            
            WPSTB_Utilities::log('Directories found in initial scan: ' . count($directories_found) . ' - ' . implode(', ', array_slice($directories_found, 0, 10)));
            
            // Try to get more objects with continuation token if we hit the limit
            if (count($objects) >= 1000) {
                WPSTB_Utilities::log('Initial scan hit limit, attempting to continue with pagination...');
                
                // Get the last key to use as a marker
                $last_key = $objects[count($objects) - 1]['Key'];
                WPSTB_Utilities::log('Last key from initial scan: ' . $last_key);
                
                try {
                    // Try to get more objects starting after the last one
                    $more_objects = $this->list_objects_with_marker($last_key, 50000);
                    if (count($more_objects) > 0) {
                        WPSTB_Utilities::log('Found ' . count($more_objects) . ' additional objects with marker-based pagination');
                        
                        // Merge with existing objects
                        $existing_keys = array_column($objects, 'Key');
                        $added = 0;
                        foreach ($more_objects as $obj) {
                            if (!in_array($obj['Key'], $existing_keys)) {
                                $objects[] = $obj;
                                $added++;
                                
                                // Track new directories
                                if (strpos($obj['Key'], '/') !== false) {
                                    $dir = explode('/', $obj['Key'])[0];
                                    if (!in_array($dir, $directories_found)) {
                                        $directories_found[] = $dir;
                                        WPSTB_Utilities::log('Discovered new directory: ' . $dir);
                                    }
                                }
                            }
                        }
                        
                        WPSTB_Utilities::log('Added ' . $added . ' new unique objects');
                        WPSTB_Utilities::log('Total directories now: ' . count($directories_found));
                        $results['root_objects'] = count($objects);
                    }
                } catch (Exception $e) {
                    WPSTB_Utilities::log('Could not continue pagination: ' . $e->getMessage());
                }
            }
            
            // Also specifically search for bug-reports folder
            WPSTB_Utilities::log('Step 2: Fetching objects from bug-reports/ directory...');
            try {
                $bug_reports_objects = $this->list_objects('bug-reports/', 5000); // Increased limit
                $results['bug_report_objects'] = count($bug_reports_objects);
                WPSTB_Utilities::log('Found ' . $results['bug_report_objects'] . ' files in bug-reports/ folder');
                
                // Log first 5 bug report keys for debugging
                if (count($bug_reports_objects) > 0) {
                    WPSTB_Utilities::log('Sample of bug-reports objects found:');
                    $sample_count = min(5, count($bug_reports_objects));
                    for ($i = 0; $i < $sample_count; $i++) {
                        WPSTB_Utilities::log('  - ' . $bug_reports_objects[$i]['Key']);
                    }
                }
                
                // Merge with main objects list, avoiding duplicates
                $existing_keys = array_column($objects, 'Key');
                $merged_count = 0;
                foreach ($bug_reports_objects as $bug_obj) {
                    if (!in_array($bug_obj['Key'], $existing_keys)) {
                        $objects[] = $bug_obj;
                        $merged_count++;
                    }
                }
                WPSTB_Utilities::log('Merged ' . $merged_count . ' unique bug-reports objects into main list');
                $results['total_objects'] = count($objects);
            } catch (Exception $e) {
                WPSTB_Utilities::log('Could not search bug-reports/ folder: ' . $e->getMessage());
            }
            
            WPSTB_Utilities::log('Step 3: Total objects after merging: ' . $results['total_objects']);
            
            // Organize files by directory/site
            $directories = array();
            $bug_report_files = array();
            $all_directories_found = array(); // Track all directories for diagnostics
            
            WPSTB_Utilities::log('=== STARTING DIRECTORY ANALYSIS ===');
            WPSTB_Utilities::log('Total objects to analyze: ' . count($objects));
            
            // Add diagnostic tracking
            $diagnostic_stats = array(
                'json_files' => 0,
                'non_json_files' => 0,
                'bug_reports' => 0,
                'diagnostic_files' => 0,
                'already_processed_dirs' => 0,
                'new_directories' => 0,
                'directories_list' => array()
            );
            
            foreach ($objects as $index => $object) {
                $key = $object['Key'];
                
                // Track all directories
                if (strpos($key, '/') !== false) {
                    $dir_parts = explode('/', $key);
                    $dir_name = $dir_parts[0];
                    if (!in_array($dir_name, $all_directories_found)) {
                        $all_directories_found[] = $dir_name;
                    }
                }
                
                // Only process JSON files
                if (!preg_match('/\.json$/i', $key)) {
                    WPSTB_Utilities::log('Skipping non-JSON file: ' . $key);
                    $diagnostic_stats['non_json_files']++;
                    $results['skipped']++;
                    continue;
                }
                
                $diagnostic_stats['json_files']++;
                
                // Check if it's a bug report
                $is_bug_report = $this->is_bug_report_file($key);
                
                if ($is_bug_report) {
                    WPSTB_Utilities::log('✓ CLASSIFIED AS BUG REPORT: ' . $key);
                    $bug_report_files[] = $object;
                    $diagnostic_stats['bug_reports']++;
                } else {
                    // Extract directory/site information
                    $directory = $this->extract_directory_from_key($key);
                    
                    if ($directory) {
                        WPSTB_Utilities::log('→ DIAGNOSTIC FILE in directory: ' . $directory . ' (' . $key . ')');
                        
                        // Track this directory in diagnostics
                        if (!in_array($directory, $diagnostic_stats['directories_list'])) {
                            $diagnostic_stats['directories_list'][] = $directory;
                        }
                        
                        // Check if we should process this directory
                        if (!$this->should_process_directory($directory)) {
                            WPSTB_Utilities::log('Directory already processed, skipping file: ' . $key);
                            // Only count the directory once, not for each file
                            if (!isset($directories[$directory]) && !in_array($directory . '_counted', $diagnostic_stats)) {
                                $diagnostic_stats['already_processed_dirs']++;
                                $diagnostic_stats[$directory . '_counted'] = true;
                            }
                            $results['skipped']++;
                            continue;
                        }
                        
                        // Add to directory analysis
                        if (!isset($directories[$directory])) {
                            $directories[$directory] = array(
                                'files' => array(),
                                'latest_file' => null,
                                'latest_timestamp' => 0
                            );
                            $diagnostic_stats['new_directories']++;
                        }
                        
                        // Extract timestamp from filename
                        $timestamp = $this->extract_timestamp_from_key($key);
                        
                        $directories[$directory]['files'][] = array(
                            'object' => $object,
                            'timestamp' => $timestamp
                        );
                        
                        // Keep track of the latest file in this directory
                        if ($timestamp > $directories[$directory]['latest_timestamp']) {
                            $directories[$directory]['latest_file'] = $object;
                            $directories[$directory]['latest_timestamp'] = $timestamp;
                        }
                        
                        $diagnostic_stats['diagnostic_files']++;
                        WPSTB_Utilities::log('  - Added to directory: ' . $directory . ' (timestamp: ' . $timestamp . ')');
                    } else {
                        WPSTB_Utilities::log('  - Could not extract directory from: ' . $key);
                        $results['skipped']++;
                    }
                }
            }
            
            $results['total_directories'] = count($directories);
            
            WPSTB_Utilities::log('=== DIRECTORY ANALYSIS COMPLETE ===');
            WPSTB_Utilities::log('=== DIAGNOSTIC SUMMARY ===');
            WPSTB_Utilities::log('Total directories found in bucket: ' . count($all_directories_found));
            WPSTB_Utilities::log('All directories in bucket: ' . implode(', ', array_slice($all_directories_found, 0, 20)));
            WPSTB_Utilities::log('JSON files found: ' . $diagnostic_stats['json_files']);
            WPSTB_Utilities::log('Non-JSON files skipped: ' . $diagnostic_stats['non_json_files']);
            WPSTB_Utilities::log('Bug report files found: ' . $diagnostic_stats['bug_reports']);
            WPSTB_Utilities::log('Diagnostic files found: ' . $diagnostic_stats['diagnostic_files']);
            WPSTB_Utilities::log('New directories to process: ' . $diagnostic_stats['new_directories']);
            WPSTB_Utilities::log('Already processed directories: ' . $diagnostic_stats['already_processed_dirs']);
            WPSTB_Utilities::log('Directories with diagnostic files: ' . implode(', ', array_slice($diagnostic_stats['directories_list'], 0, 10)));
            
            // Get list of already processed directories
            $processed_dirs = $this->get_processed_directories();
            WPSTB_Utilities::log('Previously processed directories in database: ' . count($processed_dirs));
            if (count($processed_dirs) > 0) {
                WPSTB_Utilities::log('Previously processed: ' . implode(', ', array_slice($processed_dirs, 0, 10)));
            }
            
            WPSTB_Utilities::log('Directories to process in this scan: ' . count($directories));
            
            // Log directory summary
            foreach ($directories as $dir => $info) {
                WPSTB_Utilities::log('Directory to process: ' . $dir . ' - ' . count($info['files']) . ' files, latest: ' . ($info['latest_file'] ? $info['latest_file']['Key'] : 'none'));
            }
            
            // Store diagnostics in results for display
            $results['diagnostics'] = array(
                'all_directories_in_bucket' => $all_directories_found,
                'total_directories_in_bucket' => count($all_directories_found),
                'json_files' => $diagnostic_stats['json_files'],
                'non_json_files' => $diagnostic_stats['non_json_files'],
                'bug_reports' => $diagnostic_stats['bug_reports'],
                'diagnostic_files' => $diagnostic_stats['diagnostic_files'],
                'new_directories' => $diagnostic_stats['new_directories'],
                'already_processed_dirs' => $diagnostic_stats['already_processed_dirs'],
                'directories_with_files' => $diagnostic_stats['directories_list'],
                'previously_processed' => $processed_dirs,
                'directories_to_process' => array_keys($directories)
            );
            
            // Process all bug reports (allow multiple per site)
            foreach ($bug_report_files as $object) {
                $key = $object['Key'];
                
                // For bug reports, only skip if this exact file was already processed
                if (WPSTB_Database::is_file_processed($key)) {
                    WPSTB_Utilities::log('Bug report already processed, skipping: ' . $key);
                    $results['skipped']++;
                    continue;
                }
                
                WPSTB_Utilities::log('Processing bug report: ' . $key);
                
                try {
                    // Get file content
                    $content = $this->get_object($key);
                    $data = json_decode($content, true);
                    
                    if ($data === null) {
                        WPSTB_Utilities::log('Invalid JSON in bug report: ' . $key, 'error');
                        $results['errors']++;
                        continue;
                    }
                    
                    $this->process_bug_report($key, $data);
                    $results['new_bug_reports']++;
                    WPSTB_Utilities::log('Successfully processed bug report: ' . $key);
                    
                    // Mark as processed
                    WPSTB_Database::mark_file_processed($key, md5($content));
                    $results['processed']++;
                    
                } catch (Exception $e) {
                    $error_msg = 'Error processing bug report ' . $key . ': ' . $e->getMessage();
                    WPSTB_Utilities::log($error_msg, 'error');
                    $results['errors']++;
                }
            }
            
            // Process one file per directory (the latest one)
            foreach ($directories as $directory => $info) {
                if (!$info['latest_file']) {
                    WPSTB_Utilities::log('No latest file found for directory: ' . $directory);
                    continue;
                }
                
                $object = $info['latest_file'];
                $key = $object['Key'];
                
                WPSTB_Utilities::log('Processing directory: ' . $directory . ' using latest file: ' . $key);
                
                try {
                    // Get file content
                    $content = $this->get_object($key);
                    $data = json_decode($content, true);
                    
                    if ($data === null) {
                        WPSTB_Utilities::log('Invalid JSON in diagnostic file: ' . $key, 'error');
                        $results['errors']++;
                        continue;
                    }
                    
                    $this->process_diagnostic_data($key, $data);
                    $results['new_diagnostic_files']++;
                    $results['processed_directories']++;
                    WPSTB_Utilities::log('Successfully processed directory: ' . $directory);
                    
                    // Mark the directory as processed
                    $this->mark_directory_processed($directory);
                    
                    // Also mark the specific file as processed
                    WPSTB_Database::mark_file_processed($key, md5($content));
                    $results['processed']++;
                    
                } catch (Exception $e) {
                    $error_msg = 'Error processing directory ' . $directory . ' (file: ' . $key . '): ' . $e->getMessage();
                    WPSTB_Utilities::log($error_msg, 'error');
                    $results['errors']++;
                }
            }
            
            // Update last scan time
            update_option('wpstb_last_scan', current_time('mysql'));
            
            WPSTB_Utilities::log('Scan completed. Processed: ' . $results['processed'] . ', Directories: ' . $results['processed_directories'] . ', Errors: ' . $results['errors']);
            
        } catch (Exception $e) {
            $error_msg = 'Bucket scan failed: ' . $e->getMessage();
            WPSTB_Utilities::log($error_msg, 'error');
            throw new Exception($error_msg);
        }
        
        return $results;
    }
    
    /**
     * Validate credentials based on S3 service type
     */
    private function validate_credentials() {
        $access_key_length = strlen($this->access_key);
        $secret_key_length = strlen($this->secret_key);
        
        // Detect service type based on endpoint
        $service_info = $this->detect_service_type();
        $service_type = $service_info['type'];
        $expected_access_lengths = is_array($service_info['access_key_length']) ? $service_info['access_key_length'] : array($service_info['access_key_length']);
        $expected_secret_lengths = is_array($service_info['secret_key_length']) ? $service_info['secret_key_length'] : array($service_info['secret_key_length']);
        
        $errors = array();
        
        if (!in_array($access_key_length, $expected_access_lengths)) {
            $expected_str = implode(' or ', $expected_access_lengths);
            $errors[] = "Access key length is {$access_key_length}, but {$service_type} expects {$expected_str} characters";
        }
        
        if (!in_array($secret_key_length, $expected_secret_lengths)) {
            $expected_str = implode(' or ', $expected_secret_lengths);
            $errors[] = "Secret key length is {$secret_key_length}, but {$service_type} expects {$expected_str} characters";
        }
        
        if (!empty($errors)) {
            return array(
                'valid' => false,
                'message' => 'Credential validation failed for ' . $service_type . ': ' . implode(', ', $errors)
            );
        }
        
        return array('valid' => true, 'message' => 'Credentials validated for ' . $service_type . ' (Access: ' . $access_key_length . ' chars, Secret: ' . $secret_key_length . ' chars)');
    }
    
    /**
     * Detect S3 service type based on endpoint
     */
    private function detect_service_type() {
        $endpoint_lower = strtolower($this->endpoint);
        
        // Cloudflare R2
        if (strpos($endpoint_lower, 'r2.cloudflarestorage.com') !== false || 
            strpos($endpoint_lower, 'cloudflare') !== false) {
            // Cloudflare R2 accepts multiple credential formats
            // User confirmed their 40/64 character credentials work with R2
            return array(
                'type' => 'Cloudflare R2',
                'access_key_length' => array(32, 40),
                'secret_key_length' => array(43, 64)
            );
        }
        
        // DigitalOcean Spaces
        if (strpos($endpoint_lower, 'digitaloceanspaces.com') !== false) {
            return array(
                'type' => 'DigitalOcean Spaces',
                'access_key_length' => 32,
                'secret_key_length' => 43
            );
        }
        
        // Wasabi
        if (strpos($endpoint_lower, 'wasabisys.com') !== false) {
            return array(
                'type' => 'Wasabi',
                'access_key_length' => 20,
                'secret_key_length' => 40
            );
        }
        
        // Linode Object Storage
        if (strpos($endpoint_lower, 'linodeobjects.com') !== false) {
            return array(
                'type' => 'Linode Object Storage',
                'access_key_length' => 32,
                'secret_key_length' => 43
            );
        }
        
        // MinIO or other generic S3
        if (strpos($endpoint_lower, 'amazonaws.com') === false) {
            return array(
                'type' => 'Generic S3-Compatible Service',
                'access_key_length' => 32, // Most common for non-AWS
                'secret_key_length' => 43
            );
        }
        
        // Default to AWS S3
        return array(
            'type' => 'AWS S3',
            'access_key_length' => 20,
            'secret_key_length' => 40
        );
    }

    /**
     * Test Cloudflare R2 specific authentication methods
     */
    private function test_cloudflare_r2_auth() {
        WPSTB_Utilities::log('Trying Cloudflare R2 specific authentication...');
        
        $path = '/' . $this->bucket . '/';
        $query_string = 'list-type=2&max-keys=1';
        $url = $this->endpoint . $path . '?' . $query_string;
        
        // Try simplified headers without x-amz-content-sha256
        $headers = $this->get_cloudflare_r2_headers('GET', $path, $query_string);
        
        WPSTB_Utilities::log('Testing simplified R2 auth headers...');
        
        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'HTTP request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        WPSTB_Utilities::log('R2 alternative auth response code: ' . $response_code);
        
        if ($response_code === 200) {
            return array('success' => true, 'message' => 'Cloudflare R2 connection successful with alternative authentication!');
        } else {
            return array('success' => false, 'message' => "Alternative auth failed with status {$response_code}. Response: " . substr($body, 0, 200));
        }
    }
    
    /**
     * Generate simplified headers for Cloudflare R2
     */
    private function get_cloudflare_r2_headers($method, $path, $query_string = '') {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        // Try with just basic headers for R2
        $canonical_headers = "host:" . $host . "\n" . 
                           "x-amz-date:" . $timestamp . "\n";
        $signed_headers = "host;x-amz-date";
        
        $payload_hash = hash('sha256', '');
        
        $canonical_request = $method . "\n" . 
                           $path . "\n" . 
                           $query_string . "\n" . 
                           $canonical_headers . "\n" . 
                           $signed_headers . "\n" . 
                           $payload_hash;
        
        // Create string to sign with 'auto' region for R2
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $date . "/auto/s3/aws4_request";
        $string_to_sign = $algorithm . "\n" . 
                         $timestamp . "\n" . 
                         $credential_scope . "\n" . 
                         hash('sha256', $canonical_request);
        
        // Calculate signature
        $signing_key = $this->get_signature_key($this->secret_key, $date, 'auto', 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        
        // Create authorization header
        $authorization = $algorithm . ' Credential=' . $this->access_key . '/' . $credential_scope . 
                        ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
        
        WPSTB_Utilities::log('=== Cloudflare R2 Simplified Auth Debug ===');
        WPSTB_Utilities::log('Method: ' . $method);
        WPSTB_Utilities::log('Path: ' . $path);
        WPSTB_Utilities::log('Query String: ' . $query_string);
        WPSTB_Utilities::log('Host: ' . $host);
        WPSTB_Utilities::log('Timestamp: ' . $timestamp);
        WPSTB_Utilities::log('Canonical Request Hash: ' . hash('sha256', $canonical_request));
        WPSTB_Utilities::log('Authorization: ' . substr($authorization, 0, 100) . '...');
        
        return array(
            'Authorization' => $authorization,
            'X-Amz-Date' => $timestamp,
            'Host' => $host
        );
    }

    /**
     * Generate AWS Signature Version 4 headers
     */
    private function get_signed_headers($method, $path, $query_string = '', $payload = '') {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        // Calculate payload hash
        $payload_hash = hash('sha256', $payload);
        
        // For Cloudflare R2, try different region approaches
        $region = $this->region;
        if (strpos($this->endpoint, 'r2.cloudflarestorage.com') !== false) {
            // Cloudflare R2 might need 'auto' region or account-specific region
            $region = 'auto';
        }
        
        // Create canonical headers (must be in alphabetical order)
        $canonical_headers = "host:" . $host . "\n" . 
                           "x-amz-content-sha256:" . $payload_hash . "\n" . 
                           "x-amz-date:" . $timestamp . "\n";
        $signed_headers = "host;x-amz-content-sha256;x-amz-date";
        
        $canonical_request = $method . "\n" . 
                           $path . "\n" . 
                           $query_string . "\n" . 
                           $canonical_headers . "\n" . 
                           $signed_headers . "\n" . 
                           $payload_hash;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $date . "/" . $region . "/s3/aws4_request";
        $string_to_sign = $algorithm . "\n" . 
                         $timestamp . "\n" . 
                         $credential_scope . "\n" . 
                         hash('sha256', $canonical_request);
        
        // Calculate signature
        $signing_key = $this->get_signature_key($this->secret_key, $date, $region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        
        // Create authorization header
        $authorization = $algorithm . ' Credential=' . $this->access_key . '/' . $credential_scope . 
                        ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
        
        // Enhanced debugging for Cloudflare R2
        WPSTB_Utilities::log('=== AWS Signature V4 Debug ===');
        WPSTB_Utilities::log('Method: ' . $method);
        WPSTB_Utilities::log('Path: ' . $path);
        WPSTB_Utilities::log('Query String: ' . $query_string);
        WPSTB_Utilities::log('Host: ' . $host);
        WPSTB_Utilities::log('Region: ' . $region);
        WPSTB_Utilities::log('Timestamp: ' . $timestamp);
        WPSTB_Utilities::log('Payload Hash: ' . $payload_hash);
        WPSTB_Utilities::log('Canonical Request Hash: ' . hash('sha256', $canonical_request));
        WPSTB_Utilities::log('Credential Scope: ' . $credential_scope);
        WPSTB_Utilities::log('Authorization: ' . substr($authorization, 0, 100) . '...');
        
        return array(
            'Authorization' => $authorization,
            'X-Amz-Content-Sha256' => $payload_hash,
            'X-Amz-Date' => $timestamp,
            'Host' => $host
        );
    }
    
    /**
     * Get signature key for AWS Signature Version 4
     */
    private function get_signature_key($key, $date, $region, $service) {
        $k_date = hash_hmac('sha256', $date, 'AWS4' . $key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        
        return $k_signing;
    }
    
    /**
     * Process bug report data
     */
    public function process_bug_report($file_path, $data) {
        WPSTB_Utilities::log('Processing bug report with data: ' . print_r($data, true));
        
        $insert_data = array(
            'site_key' => $data['siteKey'] ?? '',
            'report_id' => basename($file_path, '.json'),
            'email' => $data['report']['email'] ?? '',
            'message' => $data['report']['message'] ?? '',
            'priority' => $data['report']['priority'] ?? '',
            'severity' => $data['report']['severity'] ?? '',
            'steps_to_reproduce' => $data['report']['stepsToReproduce'] ?? '',
            'expected_behavior' => $data['report']['expectedBehavior'] ?? '',
            'actual_behavior' => $data['report']['actualBehavior'] ?? '',
            'frequency' => $data['report']['frequency'] ?? '',
            'environment_os' => $data['report']['environment']['os'] ?? '',
            'environment_browser' => $data['report']['environment']['browser'] ?? '',
            'environment_device' => $data['report']['environment']['device_type'] ?? '',
            'wp_version' => $data['siteInfo']['wp_version'] ?? '',
            'php_version' => $data['siteInfo']['php_version'] ?? '',
            'site_url' => $data['siteInfo']['site_url'] ?? '',
            'plugin_version' => $data['siteInfo']['plugin_version'] ?? '',
            'current_theme' => $data['siteInfo']['current_theme'] ?? '',
            'timestamp' => $this->parse_timestamp($data['timestamp'] ?? '')
        );
        
        WPSTB_Utilities::log('Inserting bug report data: ' . print_r($insert_data, true));
        
        $result = WPSTB_Database::insert_bug_report($insert_data);
        
        if ($result === false) {
            global $wpdb;
            WPSTB_Utilities::log('Bug report insertion failed. MySQL error: ' . $wpdb->last_error, 'error');
        } else {
            WPSTB_Utilities::log('Bug report inserted successfully with ID: ' . $result);
        }
        
        return $result;
    }
    
    /**
     * Process diagnostic data
     */
    public function process_diagnostic_data($file_path, $data) {
        WPSTB_Utilities::log('Processing diagnostic data for: ' . $file_path);
        
        // Extract site key and URL
        $site_key = $data['siteInfo']['siteKey'] ?? $data['siteKey'] ?? '';
        $site_url = $this->extract_site_url($data['clientInfo']['userAgent'] ?? '');
        
        if (empty($site_key)) {
            // Try to extract from file path if not in data
            if (preg_match('/([a-f0-9]{32,64})\/\d+\.json$/i', $file_path, $matches)) {
                $site_key = $matches[1];
            }
        }
        
        WPSTB_Utilities::log('Extracted site_key: ' . $site_key . ' for file: ' . $file_path);
        
        $diagnostic_data = array(
            'site_key' => $site_key,
            'file_path' => $file_path,
            'site_url' => $site_url,
            'wp_version' => $data['environment']['wp_version'] ?? '',
            'php_version' => $data['environment']['php_version'] ?? '',
            'mysql_version' => $data['environment']['mysql_version'] ?? '',
            'server_software' => $data['environment']['server_software'] ?? '',
            'os' => $data['environment']['os'] ?? '',
            'memory_limit' => $data['environment']['memory_limit'] ?? '',
            'max_execution_time' => $data['environment']['max_execution_time'] ?? '',
            'hosting_provider_id' => $data['environment']['hosting_provider_id'] ?? null,
            'hosting_package_id' => $data['environment']['hosting_package_id'] ?? '',
            'country' => $data['clientInfo']['country'] ?? '',
            'region' => $data['clientInfo']['region'] ?? '',
            'city' => $data['clientInfo']['city'] ?? '',
            'timestamp' => $this->parse_timestamp($data['timestamp'] ?? '')
        );
        
        WPSTB_Utilities::log('Inserting diagnostic data: ' . print_r($diagnostic_data, true));
        
        $diagnostic_id = WPSTB_Database::insert_diagnostic_data($diagnostic_data);
        
        if ($diagnostic_id === false) {
            global $wpdb;
            WPSTB_Utilities::log('Diagnostic data insertion failed. MySQL error: ' . $wpdb->last_error, 'error');
            return false;
        }
        
        WPSTB_Utilities::log('Diagnostic data inserted with ID: ' . $diagnostic_id);
        
        // Process plugins
        if (!empty($data['environment']['active_plugins']) && $diagnostic_id) {
            $plugin_count = 0;
            foreach ($data['environment']['active_plugins'] as $plugin) {
                if (WPSTB_Database::insert_site_plugin($diagnostic_id, $plugin['name'] ?? '', $plugin['version'] ?? '')) {
                    $plugin_count++;
                }
            }
            WPSTB_Utilities::log('Inserted ' . $plugin_count . ' plugins for diagnostic ID: ' . $diagnostic_id);
        }
        
        return $diagnostic_id;
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
        if (empty($timestamp)) return null;
        $date = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $timestamp);
        if ($date === false) $date = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $timestamp);
        return $date ? $date->format('Y-m-d H:i:s') : null;
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
     * Extract directory/site identifier from file key
     */
    private function extract_directory_from_key($key) {
        // Pattern: site_hash/timestamp.json (numeric timestamp)
        if (preg_match('/^([a-f0-9]{32,64})\/\d+\.json$/i', $key, $matches)) {
            return $matches[1]; // Return the site hash as directory identifier
        }
        
        // Pattern: site_hash/date-formatted-timestamp.json (e.g., 2025-06-24T17-04-36-510Z.json)
        if (preg_match('/^([a-f0-9]{32,64})\/\d{4}-\d{2}-\d{2}T[\d\-]+Z?\.json$/i', $key, $matches)) {
            return $matches[1]; // Return the site hash as directory identifier
        }
        
        // Pattern: directory_name/any-filename.json (fallback for any directory/file structure)
        if (preg_match('/^([^\/]+)\/[^\/]+\.json$/i', $key, $matches)) {
            return $matches[1]; // Return the directory name
        }
        
        return null;
    }
    
    /**
     * Extract timestamp from file key
     */
    private function extract_timestamp_from_key($key) {
        // Pattern: site_hash/numeric_timestamp.json
        if (preg_match('/\/(\d+)\.json$/i', $key, $matches)) {
            return intval($matches[1]);
        }
        
        // Pattern: site_hash/date-formatted-timestamp.json (e.g., 2025-06-24T17-04-36-510Z.json)
        if (preg_match('/\/(\d{4}-\d{2}-\d{2}T[\d\-]+Z?)\.json$/i', $key, $matches)) {
            // Convert date string to timestamp
            $date_str = str_replace(array('T', 'Z'), array(' ', ''), $matches[1]);
            $date_str = str_replace('-', ':', substr($date_str, 11)); // Fix time part
            $timestamp = strtotime($date_str);
            return $timestamp ? $timestamp : time();
        }
        
        return time(); // Return current time as fallback
    }
    
    /**
     * Check if directory should be processed (not already processed)
     */
    private function should_process_directory($directory) {
        $processed_dirs = get_option('wpstb_processed_directories', array());
        return !in_array($directory, $processed_dirs);
    }
    
    /**
     * Mark directory as processed
     */
    private function mark_directory_processed($directory) {
        $processed_dirs = get_option('wpstb_processed_directories', array());
        if (!in_array($directory, $processed_dirs)) {
            $processed_dirs[] = $directory;
            update_option('wpstb_processed_directories', $processed_dirs);
        }
    }
    
    /**
     * Get list of processed directories
     */
    public function get_processed_directories() {
        return get_option('wpstb_processed_directories', array());
    }
    
    /**
     * Clear processed directories list
     */
    public function clear_processed_directories() {
        delete_option('wpstb_processed_directories');
    }
    
    /**
     * List objects with a start-after marker for continuation
     */
    public function list_objects_with_marker($start_after, $max_keys = 10000) {
        WPSTB_Utilities::log('Starting marker-based S3 list request starting after: ' . $start_after);
        
        $all_objects = array();
        $continuation_token = null;
        $page_count = 0;
        $max_pages = 50;
        
        do {
            $page_count++;
            $path = '/' . $this->bucket . '/';
            $query_params = array(
                'list-type' => '2',
                'max-keys' => min($max_keys, 1000),
                'start-after' => $start_after  // Start after the specified key
            );
            
            if ($continuation_token !== null) {
                $query_params['continuation-token'] = $continuation_token;
                unset($query_params['start-after']); // Don't use both
            }
            
            // Build query string with proper encoding
            $query_parts = array();
            foreach ($query_params as $key => $value) {
                $query_parts[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
            $query_string = implode('&', $query_parts);
            
            $url = $this->endpoint . $path . '?' . $query_string;
            
            WPSTB_Utilities::log('Making marker-based S3 request (page ' . $page_count . ')...');
            
            // Generate signed headers
            $headers = $this->get_signed_headers('GET', $path, $query_string, '');
            
            $response = wp_remote_get($url, array(
                'headers' => $headers,
                'timeout' => 30,
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                $error = 'HTTP request failed: ' . $response->get_error_message();
                WPSTB_Utilities::log($error, 'error');
                throw new Exception($error);
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                WPSTB_Utilities::log('S3 error response: ' . substr($body, 0, 500), 'error');
                throw new Exception("S3 request failed with status {$response_code}");
            }
            
            $xml = simplexml_load_string($body);
            
            if ($xml === false) {
                throw new Exception('Failed to parse XML response from S3');
            }
            
            // Parse objects from this page
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $content) {
                    $all_objects[] = array(
                        'Key' => (string)$content->Key,
                        'Size' => (int)$content->Size,
                        'LastModified' => (string)$content->LastModified
                    );
                }
            }
            
            // Check if there are more pages
            $is_truncated = isset($xml->IsTruncated) && (string)$xml->IsTruncated === 'true';
            $continuation_token = isset($xml->NextContinuationToken) ? (string)$xml->NextContinuationToken : null;
            
            $objects_on_page = isset($xml->Contents) ? count($xml->Contents) : 0;
            WPSTB_Utilities::log('Marker page ' . $page_count . ' retrieved ' . $objects_on_page . ' objects. Total so far: ' . count($all_objects));
            
            // Break if we've reached the desired number of objects
            if (count($all_objects) >= $max_keys) {
                WPSTB_Utilities::log('Reached max_keys limit of ' . $max_keys);
                $all_objects = array_slice($all_objects, 0, $max_keys);
                break;
            }
            
            // Safety check
            if ($page_count >= $max_pages) {
                WPSTB_Utilities::log('Reached maximum page limit');
                break;
            }
            
        } while ($is_truncated && $continuation_token !== null);
        
        WPSTB_Utilities::log('Marker-based retrieval complete. Got ' . count($all_objects) . ' objects');
        return $all_objects;
    }
    
    /**
     * List directories using S3 delimiter feature for efficient directory discovery
     */
    public function list_directories_with_delimiter() {
        WPSTB_Utilities::log('Discovering directories using S3 delimiter feature...');
        
        $directories = array();
        $continuation_token = null;
        $page_count = 0;
        $max_pages = 50;
        
        do {
            $page_count++;
            $path = '/' . $this->bucket . '/';
            $query_params = array(
                'list-type' => '2',
                'delimiter' => '/',  // This tells S3 to group by directories
                'max-keys' => '1000'
            );
            
            if ($continuation_token !== null) {
                $query_params['continuation-token'] = $continuation_token;
            }
            
            // Build query string with proper encoding
            $query_parts = array();
            foreach ($query_params as $key => $value) {
                $query_parts[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
            $query_string = implode('&', $query_parts);
            
            $url = $this->endpoint . $path . '?' . $query_string;
            
            WPSTB_Utilities::log('Fetching directory list (page ' . $page_count . ')...');
            
            // Generate signed headers
            $headers = $this->get_signed_headers('GET', $path, $query_string, '');
            
            $response = wp_remote_get($url, array(
                'headers' => $headers,
                'timeout' => 30,
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                $error = 'HTTP request failed: ' . $response->get_error_message();
                WPSTB_Utilities::log($error, 'error');
                throw new Exception($error);
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                WPSTB_Utilities::log('S3 error response: ' . substr($body, 0, 500), 'error');
                throw new Exception("S3 request failed with status {$response_code}");
            }
            
            $xml = simplexml_load_string($body);
            
            if ($xml === false) {
                throw new Exception('Failed to parse XML response from S3');
            }
            
            // CommonPrefixes contains the directories when using delimiter
            if (isset($xml->CommonPrefixes)) {
                foreach ($xml->CommonPrefixes as $prefix) {
                    $dir = rtrim((string)$prefix->Prefix, '/');
                    if (!empty($dir) && !in_array($dir, $directories)) {
                        $directories[] = $dir;
                    }
                }
            }
            
            // Check if there are more pages
            $is_truncated = isset($xml->IsTruncated) && (string)$xml->IsTruncated === 'true';
            $continuation_token = isset($xml->NextContinuationToken) ? (string)$xml->NextContinuationToken : null;
            
            WPSTB_Utilities::log('Directory discovery page ' . $page_count . ' found ' . count($directories) . ' directories so far. Truncated: ' . ($is_truncated ? 'yes' : 'no'));
            
            if ($page_count >= $max_pages) {
                WPSTB_Utilities::log('Reached maximum page limit for directory discovery');
                break;
            }
            
        } while ($is_truncated && $continuation_token !== null);
        
        WPSTB_Utilities::log('Directory discovery complete. Found ' . count($directories) . ' directories');
        return $directories;
    }
    
    /**
     * List all unique directories in the bucket
     */
    public function list_all_directories() {
        WPSTB_Utilities::log('=== LISTING ALL DIRECTORIES IN BUCKET ===');
        
        try {
            // Get all objects with high limit
            $objects = $this->list_objects('', 50000);
            WPSTB_Utilities::log('Total objects found: ' . count($objects));
            
            $directories = array();
            $root_level_items = array();
            
            foreach ($objects as $object) {
                $key = $object['Key'];
                
                // Extract directory from key
                if (strpos($key, '/') !== false) {
                    $parts = explode('/', $key);
                    $directory = $parts[0];
                    
                    if (!isset($directories[$directory])) {
                        $directories[$directory] = array(
                            'count' => 0,
                            'sample_files' => array()
                        );
                    }
                    
                    $directories[$directory]['count']++;
                    
                    // Keep first 3 sample files
                    if (count($directories[$directory]['sample_files']) < 3) {
                        $directories[$directory]['sample_files'][] = $key;
                    }
                } else {
                    // Root level file
                    $root_level_items[] = $key;
                }
            }
            
            WPSTB_Utilities::log('Found ' . count($directories) . ' unique directories');
            WPSTB_Utilities::log('Found ' . count($root_level_items) . ' root level files');
            
            // Log directory details
            WPSTB_Utilities::log('Directory listing:');
            foreach ($directories as $dir => $info) {
                WPSTB_Utilities::log('  Directory: ' . $dir . ' (' . $info['count'] . ' files)');
                foreach ($info['sample_files'] as $sample) {
                    WPSTB_Utilities::log('    - ' . $sample);
                }
            }
            
            // Log root level items
            if (count($root_level_items) > 0) {
                WPSTB_Utilities::log('Root level files:');
                foreach ($root_level_items as $item) {
                    WPSTB_Utilities::log('  - ' . $item);
                }
            }
            
            return array(
                'directories' => $directories,
                'root_items' => $root_level_items,
                'total_objects' => count($objects)
            );
            
        } catch (Exception $e) {
            WPSTB_Utilities::log('Error listing directories: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
} 