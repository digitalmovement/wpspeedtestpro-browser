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
            $objects = $this->list_objects('', 1);
            $count = count($objects);
            $message = "Connection successful! Found {$count} object(s) in bucket.";
            WPSTB_Utilities::log($message);
            return array('success' => true, 'message' => $message);
        } catch (Exception $e) {
            $error_message = 'Connection failed: ' . $e->getMessage();
            WPSTB_Utilities::log($error_message, 'error');
            return array('success' => false, 'message' => $error_message);
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
            
            foreach ($objects as $object) {
                $key = $object['Key'];
                
                // Skip if already processed
                if (WPSTB_Database::is_file_processed($key)) {
                    $results['skipped']++;
                    continue;
                }
                
                // Only process JSON files
                if (!preg_match('/\.json$/i', $key)) {
                    WPSTB_Utilities::log('Skipping non-JSON file: ' . $key);
                    $results['skipped']++;
                    continue;
                }
                
                WPSTB_Utilities::log('Processing file: ' . $key);
                
                try {
                    // Get file content
                    $content = $this->get_object($key);
                    $data = json_decode($content, true);
                    
                    if ($data === null) {
                        WPSTB_Utilities::log('Invalid JSON in file: ' . $key, 'error');
                        $results['errors']++;
                        continue;
                    }
                    
                    // Process based on file location
                    if (strpos($key, 'bug-reports/') !== false) {
                        $this->process_bug_report($key, $data);
                        $results['new_bug_reports']++;
                        WPSTB_Utilities::log('Processed bug report: ' . $key);
                    } else {
                        $this->process_diagnostic_data($key, $data);
                        $results['new_diagnostic_files']++;
                        WPSTB_Utilities::log('Processed diagnostic file: ' . $key);
                    }
                    
                    // Mark as processed
                    WPSTB_Database::mark_file_processed($key, md5($content));
                    $results['processed']++;
                    
                } catch (Exception $e) {
                    $error_msg = 'Error processing file ' . $key . ': ' . $e->getMessage();
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
            $message = 'Credential validation failed for ' . $service_type . ': ' . implode(', ', $errors);
            
            // Add help message if available
            if (isset($service_info['help_message'])) {
                $message .= "\n\n" . $service_info['help_message'];
            }
            
            return array('valid' => false, 'message' => $message);
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
            // Cloudflare R2 server STRICTLY requires R2 API tokens (32/43 characters)
            // Global API Keys or other credential types will be rejected by the server
            return array(
                'type' => 'Cloudflare R2',
                'access_key_length' => 32,
                'secret_key_length' => 43,
                'help_message' => 'You must create R2-specific API tokens. Go to Cloudflare Dashboard → R2 Object Storage → Manage R2 API Tokens → Create Token'
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
     * Generate AWS Signature Version 4 headers
     */
    private function get_signed_headers($method, $path, $query_string = '', $payload = '') {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        // Calculate payload hash
        $payload_hash = hash('sha256', $payload);
        
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
        $credential_scope = $date . "/" . $this->region . "/s3/aws4_request";
        $string_to_sign = $algorithm . "\n" . 
                         $timestamp . "\n" . 
                         $credential_scope . "\n" . 
                         hash('sha256', $canonical_request);
        
        // Calculate signature
        $signing_key = $this->get_signature_key($this->secret_key, $date, $this->region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        
        // Create authorization header
        $authorization = $algorithm . ' Credential=' . $this->access_key . '/' . $credential_scope . 
                        ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
        
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
        if (empty($timestamp)) return null;
        $date = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $timestamp);
        if ($date === false) $date = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $timestamp);
        return $date ? $date->format('Y-m-d H:i:s') : null;
    }
} 