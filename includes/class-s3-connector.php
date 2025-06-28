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
     * List objects in S3 bucket with proper authentication
     */
    public function list_objects($prefix = '', $max_keys = 1000) {
        $path = '/' . $this->bucket . '/';
        $query_params = array(
            'list-type' => '2',
            'max-keys' => $max_keys
        );
        
        if (!empty($prefix)) {
            $query_params['prefix'] = $prefix;
        }
        
        $query_string = http_build_query($query_params);
        $url = $this->endpoint . $path . '?' . $query_string;
        
        WPSTB_Utilities::log('Making S3 list request to: ' . $url);
        
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
            throw new Exception("S3 request failed with status {$response_code}. Response: " . substr($body, 0, 200));
        }
        
        $xml = simplexml_load_string($body);
        
        if ($xml === false) {
            WPSTB_Utilities::log('Failed to parse XML response: ' . substr($body, 0, 500), 'error');
            throw new Exception('Failed to parse XML response from S3');
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
        
        WPSTB_Utilities::log('Successfully parsed ' . count($objects) . ' objects from S3 response');
        return $objects;
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
     * Scan S3 bucket for new files with detailed logging
     */
    public function scan_bucket() {
        WPSTB_Utilities::log('Starting S3 bucket scan...');
        
        $results = array(
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'new_bug_reports' => 0,
            'new_diagnostic_files' => 0,
            'total_objects' => 0
        );
        
        try {
            // Get all objects
            $objects = $this->list_objects();
            $results['total_objects'] = count($objects);
            
            WPSTB_Utilities::log('Found ' . $results['total_objects'] . ' total objects in bucket');
            
            // Group files by site (hash key) for better processing
            $files_by_site = array();
            $bug_report_files = array();
            
            foreach ($objects as $object) {
                $key = $object['Key'];
                
                // Only process JSON files
                if (!preg_match('/\.json$/i', $key)) {
                    WPSTB_Utilities::log('Skipping non-JSON file: ' . $key);
                    $results['skipped']++;
                    continue;
                }
                
                // Check for bug reports - be more flexible with path matching
                $is_bug_report = (
                    strpos($key, 'bug-reports/') !== false || 
                    strpos($key, 'bug-reports') === 0 ||
                    strpos(strtolower($key), 'bug-report') !== false
                );
                
                if ($is_bug_report) {
                    $bug_report_files[] = $object;
                } else {
                    // Extract site hash from path for diagnostic files
                    if (preg_match('/([a-f0-9]{32,64})\/(\d+)\.json$/i', $key, $matches)) {
                        $site_hash = $matches[1];
                        $timestamp = $matches[2];
                        
                        if (!isset($files_by_site[$site_hash]) || $timestamp > $files_by_site[$site_hash]['timestamp']) {
                            $files_by_site[$site_hash] = array(
                                'object' => $object,
                                'timestamp' => $timestamp
                            );
                        }
                    } else {
                        // If we can't extract site hash, process as individual file
                        $files_by_site[$key] = array(
                            'object' => $object,
                            'timestamp' => 0
                        );
                    }
                }
            }
            
            // Process all bug reports (allow multiple per site)
            foreach ($bug_report_files as $object) {
                $key = $object['Key'];
                
                WPSTB_Utilities::log('Found bug report: ' . $key);
                
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
                    
                    WPSTB_Utilities::log('PROCESSING BUG REPORT: ' . $key);
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
            
            // Process diagnostic files (only latest per site)
            foreach ($files_by_site as $site_identifier => $file_info) {
                $object = $file_info['object'];
                $key = $object['Key'];
                
                WPSTB_Utilities::log('Found diagnostic file: ' . $key);
                
                // For diagnostic files, check if we already have data for this site
                if (WPSTB_Database::is_site_processed($site_identifier)) {
                    WPSTB_Utilities::log('Site already processed, skipping: ' . $key);
                    $results['skipped']++;
                    continue;
                }
                
                WPSTB_Utilities::log('Processing diagnostic file: ' . $key);
                
                try {
                    // Get file content
                    $content = $this->get_object($key);
                    $data = json_decode($content, true);
                    
                    if ($data === null) {
                        WPSTB_Utilities::log('Invalid JSON in diagnostic file: ' . $key, 'error');
                        $results['errors']++;
                        continue;
                    }
                    
                    WPSTB_Utilities::log('Processing diagnostic data: ' . $key);
                    $this->process_diagnostic_data($key, $data);
                    $results['new_diagnostic_files']++;
                    WPSTB_Utilities::log('Processed diagnostic file: ' . $key);
                    
                    // Mark as processed
                    WPSTB_Database::mark_file_processed($key, md5($content));
                    $results['processed']++;
                    
                } catch (Exception $e) {
                    $error_msg = 'Error processing diagnostic file ' . $key . ': ' . $e->getMessage();
                    WPSTB_Utilities::log($error_msg, 'error');
                    $results['errors']++;
                }
            }
            
            // Update last scan time
            update_option('wpstb_last_scan', current_time('mysql'));
            
            WPSTB_Utilities::log('Scan completed. Processed: ' . $results['processed'] . ', Errors: ' . $results['errors']);
            
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
    private function process_bug_report($file_path, $data) {
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
    private function process_diagnostic_data($file_path, $data) {
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
} 