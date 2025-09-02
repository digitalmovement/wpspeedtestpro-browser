<?php
/**
 * Bulk Scanner class for processing S3 files in chunks without timeouts
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSTB_Bulk_Scanner {
    
    private $batch_size = 10; // Process 10 files per batch
    private $max_execution_time = 25; // Maximum execution time per batch (seconds)
    private $progress_option = 'wpstb_bulk_scan_progress';
    private $queue_option = 'wpstb_bulk_scan_queue';
    
    public function __construct() {
        add_action('wp_ajax_wpstb_start_bulk_scan', array($this, 'ajax_start_bulk_scan'));
        add_action('wp_ajax_wpstb_process_bulk_batch', array($this, 'ajax_process_bulk_batch'));
        add_action('wp_ajax_wpstb_get_bulk_progress', array($this, 'ajax_get_bulk_progress'));
        add_action('wp_ajax_wpstb_cancel_bulk_scan', array($this, 'ajax_cancel_bulk_scan'));
        add_action('wp_ajax_wpstb_resume_bulk_scan', array($this, 'ajax_resume_bulk_scan'));
        add_action('wpstb_bulk_scan_cron', array($this, 'process_background_scan'));
    }
    
    /**
     * Start bulk scan - prepares the file queue
     */
    public function ajax_start_bulk_scan() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $this->reset_scan_progress();
            
            $s3 = new WPSTB_S3_Connector();
            
            WPSTB_Utilities::log('=== BULK SCANNER: Starting object retrieval ===');
            
            // Get all objects from root with increased limit
            WPSTB_Utilities::log('BULK SCANNER: Fetching objects from root directory...');
            $objects = $s3->list_objects('', 100000); // Greatly increased limit for bulk processing
            WPSTB_Utilities::log('BULK SCANNER: Found ' . count($objects) . ' objects in root directory');
            
            // Also search for bug-reports folder
            try {
                WPSTB_Utilities::log('BULK SCANNER: Fetching objects from bug-reports/ directory...');
                $bug_reports_objects = $s3->list_objects('bug-reports/', 5000);
                WPSTB_Utilities::log('BULK SCANNER: Found ' . count($bug_reports_objects) . ' objects in bug-reports/ directory');
                
                $existing_keys = array_column($objects, 'Key');
                $merged_count = 0;
                foreach ($bug_reports_objects as $bug_obj) {
                    if (!in_array($bug_obj['Key'], $existing_keys)) {
                        $objects[] = $bug_obj;
                        $merged_count++;
                    }
                }
                WPSTB_Utilities::log('BULK SCANNER: Merged ' . $merged_count . ' unique bug-reports objects');
            } catch (Exception $e) {
                WPSTB_Utilities::log('BULK SCANNER: Could not search bug-reports/ folder: ' . $e->getMessage());
            }
            
            WPSTB_Utilities::log('BULK SCANNER: Total objects to process: ' . count($objects));
            
            // Filter and prepare queue
            $queue = $this->prepare_file_queue($objects);
            
            // Save queue and initialize progress
            update_option($this->queue_option, $queue);
            $progress = array(
                'status' => 'ready',
                'total_files' => count($queue),
                'processed_files' => 0,
                'processed_bug_reports' => 0,
                'processed_diagnostic_files' => 0,
                'skipped_files' => 0,
                'error_files' => 0,
                'current_batch' => 0,
                'total_batches' => ceil(count($queue) / $this->batch_size),
                'start_time' => current_time('mysql'),
                'last_update' => current_time('mysql'),
                'errors' => array()
            );
            update_option($this->progress_option, $progress);
            
            wp_send_json_success(array(
                'message' => 'Bulk scan prepared successfully',
                'total_files' => count($queue),
                'total_batches' => $progress['total_batches'],
                'progress' => $progress
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Process a batch of files
     */
    public function ajax_process_bulk_batch() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $this->process_next_batch();
            wp_send_json_success($this->get_progress());
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Get current progress
     */
    public function ajax_get_bulk_progress() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        wp_send_json_success($this->get_progress());
    }
    
    /**
     * Cancel bulk scan
     */
    public function ajax_cancel_bulk_scan() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        $this->cancel_scan();
        wp_send_json_success('Bulk scan cancelled');
    }
    
    /**
     * Resume bulk scan
     */
    public function ajax_resume_bulk_scan() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        $progress = $this->get_progress();
        if ($progress['status'] === 'paused' || $progress['status'] === 'error') {
            $progress['status'] = 'ready';
            $progress['last_update'] = current_time('mysql');
            update_option($this->progress_option, $progress);
        }
        
        wp_send_json_success($progress);
    }
    
    /**
     * Prepare file queue from S3 objects - Directory-based approach
     */
    private function prepare_file_queue($objects) {
        $queue = array();
        $directories = array();
        $bug_report_files = array();
        
        WPSTB_Utilities::log('Bulk scanner: Starting directory-based file queue preparation');
        
        foreach ($objects as $object) {
            $key = $object['Key'];
            
            // Only process JSON files
            if (!preg_match('/\.json$/i', $key)) {
                continue;
            }
            
            // Check if already processed
            if (WPSTB_Database::is_file_processed($key)) {
                continue;
            }
            
            // Classify file type
            $is_bug_report = $this->is_bug_report_file($key);
            
            if ($is_bug_report) {
                $bug_report_files[] = array(
                    'key' => $key,
                    'type' => 'bug_report',
                    'size' => $object['Size'],
                    'last_modified' => $object['LastModified']
                );
                WPSTB_Utilities::log('Bulk scanner: Added bug report file: ' . $key);
            } else {
                // Extract directory/site information
                $directory = $this->extract_directory_from_key($key);
                
                if ($directory) {
                    // Check if we should process this directory
                    if (!$this->should_process_directory($directory)) {
                        WPSTB_Utilities::log('Bulk scanner: Directory already processed, skipping: ' . $directory);
                        continue;
                    }
                    
                    // Add to directory analysis
                    if (!isset($directories[$directory])) {
                        $directories[$directory] = array(
                            'files' => array(),
                            'latest_file' => null,
                            'latest_timestamp' => 0,
                            'directory' => $directory
                        );
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
                    
                    WPSTB_Utilities::log('Bulk scanner: Added to directory ' . $directory . ' (timestamp: ' . $timestamp . ')');
                } else {
                    WPSTB_Utilities::log('Bulk scanner: Could not extract directory from: ' . $key);
                }
            }
        }
        
        // Add bug reports to queue
        foreach ($bug_report_files as $file) {
            $queue[] = $file;
        }
        
        // Add one representative file per directory to queue
        foreach ($directories as $directory => $info) {
            if ($info['latest_file']) {
                $queue[] = array(
                    'key' => $info['latest_file']['Key'],
                    'type' => 'diagnostic',
                    'size' => $info['latest_file']['Size'],
                    'last_modified' => $info['latest_file']['LastModified'],
                    'directory' => $directory,
                    'total_files_in_directory' => count($info['files'])
                );
                WPSTB_Utilities::log('Bulk scanner: Added directory ' . $directory . ' with latest file: ' . $info['latest_file']['Key']);
            }
        }
        
        WPSTB_Utilities::log('Bulk scanner: Queue prepared with ' . count($queue) . ' items (' . count($bug_report_files) . ' bug reports, ' . count($directories) . ' directories)');
        
        return $queue;
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
     * Check if file is a bug report
     */
    private function is_bug_report_file($key) {
        return strpos($key, 'bug-reports/') !== false || 
               strpos($key, 'bug-reports') === 0 || 
               strpos(strtolower($key), 'bug-report') !== false;
    }
    
    /**
     * Process next batch of files
     */
    private function process_next_batch() {
        $start_time = time();
        $progress = $this->get_progress();
        $queue = get_option($this->queue_option, array());
        
        if (empty($queue) || $progress['status'] === 'completed') {
            return;
        }
        
        $progress['status'] = 'processing';
        $progress['last_update'] = current_time('mysql');
        update_option($this->progress_option, $progress);
        
        $s3 = new WPSTB_S3_Connector();
        $batch_count = 0;
        
        while (!empty($queue) && $batch_count < $this->batch_size) {
            // Check execution time limit
            if ((time() - $start_time) > $this->max_execution_time) {
                WPSTB_Utilities::log('Bulk scan: Time limit reached, stopping batch');
                break;
            }
            
            $file_info = array_shift($queue);
            $key = $file_info['key'];
            $type = $file_info['type'];
            
            try {
                WPSTB_Utilities::log('Bulk scan: Processing ' . $key . ' (type: ' . $type . ')');
                
                // Get file content
                $content = $s3->get_object($key);
                $data = json_decode($content, true);
                
                if ($data === null) {
                    throw new Exception('Invalid JSON in file: ' . $key);
                }
                
                // Process based on type
                if ($type === 'bug_report') {
                    $s3->process_bug_report($key, $data);
                    $progress['processed_bug_reports']++;
                } else {
                    $s3->process_diagnostic_data($key, $data);
                    $progress['processed_diagnostic_files']++;
                    
                    // Mark directory as processed if this is a diagnostic file
                    if (isset($file_info['directory'])) {
                        $this->mark_directory_processed($file_info['directory']);
                        WPSTB_Utilities::log('Bulk scan: Marked directory as processed: ' . $file_info['directory']);
                    }
                }
                
                // Mark as processed
                WPSTB_Database::mark_file_processed($key, md5($content));
                $progress['processed_files']++;
                
                $log_message = 'Bulk scan: Successfully processed ' . $key;
                if (isset($file_info['directory'])) {
                    $log_message .= ' (directory: ' . $file_info['directory'] . ')';
                }
                WPSTB_Utilities::log($log_message);
                
            } catch (Exception $e) {
                $error_msg = 'Error processing ' . $key . ': ' . $e->getMessage();
                WPSTB_Utilities::log($error_msg, 'error');
                
                $progress['error_files']++;
                $progress['errors'][] = array(
                    'file' => $key,
                    'error' => $error_msg,
                    'time' => current_time('mysql')
                );
                
                // Limit error log size
                if (count($progress['errors']) > 50) {
                    array_shift($progress['errors']);
                }
            }
            
            $batch_count++;
        }
        
        // Update progress
        $progress['current_batch']++;
        $progress['last_update'] = current_time('mysql');
        
        // Check if completed
        if (empty($queue)) {
            $progress['status'] = 'completed';
            $progress['end_time'] = current_time('mysql');
            update_option('wpstb_last_scan', current_time('mysql'));
        }
        
        // Save updated queue and progress
        update_option($this->queue_option, $queue);
        update_option($this->progress_option, $progress);
    }
    
    /**
     * Get current progress
     */
    private function get_progress() {
        $progress = get_option($this->progress_option, array());
        
        // Add calculated fields
        if (!empty($progress)) {
            $progress['percentage'] = $progress['total_files'] > 0 
                ? round(($progress['processed_files'] / $progress['total_files']) * 100, 2) 
                : 0;
            
            $progress['remaining_files'] = $progress['total_files'] - $progress['processed_files'];
            $progress['remaining_batches'] = $progress['total_batches'] - $progress['current_batch'];
        }
        
        return $progress;
    }
    
    /**
     * Reset scan progress
     */
    private function reset_scan_progress() {
        delete_option($this->progress_option);
        delete_option($this->queue_option);
        wp_clear_scheduled_hook('wpstb_bulk_scan_cron');
    }
    
    /**
     * Cancel current scan
     */
    private function cancel_scan() {
        $progress = $this->get_progress();
        $progress['status'] = 'cancelled';
        $progress['end_time'] = current_time('mysql');
        update_option($this->progress_option, $progress);
        wp_clear_scheduled_hook('wpstb_bulk_scan_cron');
    }
    
    /**
     * Schedule background processing
     */
    public function schedule_background_scan() {
        if (!wp_next_scheduled('wpstb_bulk_scan_cron')) {
            wp_schedule_event(time() + 30, 'wpstb_bulk_scan_interval', 'wpstb_bulk_scan_cron');
        }
    }
    
    /**
     * Process background scan (cron job)
     */
    public function process_background_scan() {
        $progress = $this->get_progress();
        
        if ($progress['status'] === 'ready' || $progress['status'] === 'processing') {
            try {
                $this->process_next_batch();
            } catch (Exception $e) {
                WPSTB_Utilities::log('Background scan error: ' . $e->getMessage(), 'error');
            }
        }
    }
    
    /**
     * Get scan statistics
     */
    public function get_scan_statistics() {
        $progress = $this->get_progress();
        
        if (empty($progress)) {
            return null;
        }
        
        $stats = array(
            'status' => $progress['status'],
            'total_files' => $progress['total_files'],
            'processed_files' => $progress['processed_files'],
            'bug_reports' => $progress['processed_bug_reports'],
            'diagnostic_files' => $progress['processed_diagnostic_files'],
            'errors' => $progress['error_files'],
            'skipped' => $progress['skipped_files'],
            'percentage' => $progress['percentage'],
            'start_time' => $progress['start_time'],
            'last_update' => $progress['last_update']
        );
        
        if (isset($progress['end_time'])) {
            $stats['end_time'] = $progress['end_time'];
            $stats['duration'] = $this->calculate_duration($progress['start_time'], $progress['end_time']);
        }
        
        return $stats;
    }
    
    /**
     * Calculate duration between two timestamps
     */
    private function calculate_duration($start, $end) {
        $start_time = strtotime($start);
        $end_time = strtotime($end);
        $duration = $end_time - $start_time;
        
        if ($duration < 60) {
            return $duration . ' seconds';
        } elseif ($duration < 3600) {
            return floor($duration / 60) . ' minutes';
        } else {
            return floor($duration / 3600) . ' hours ' . floor(($duration % 3600) / 60) . ' minutes';
        }
    }
}

// Add custom cron interval
add_filter('cron_schedules', function($schedules) {
    $schedules['wpstb_bulk_scan_interval'] = array(
        'interval' => 60, // 1 minute
        'display' => 'Every Minute for Bulk Scan'
    );
    return $schedules;
}); 