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
            $objects = $s3->list_objects('', 2000); // Get more objects for bulk processing
            
            // Also search for bug-reports folder
            try {
                $bug_reports_objects = $s3->list_objects('bug-reports/', 2000);
                $existing_keys = array_column($objects, 'Key');
                foreach ($bug_reports_objects as $bug_obj) {
                    if (!in_array($bug_obj['Key'], $existing_keys)) {
                        $objects[] = $bug_obj;
                    }
                }
            } catch (Exception $e) {
                WPSTB_Utilities::log('Could not search bug-reports/ folder: ' . $e->getMessage());
            }
            
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
     * Prepare file queue from S3 objects
     */
    private function prepare_file_queue($objects) {
        $queue = array();
        $files_by_site = array();
        $bug_report_files = array();
        
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
            } else {
                // For diagnostic files, only keep the latest per site
                if (preg_match('/([a-f0-9]{32,64})\/(\d+)\.json$/i', $key, $matches)) {
                    $site_hash = $matches[1];
                    $timestamp = $matches[2];
                    
                    if (!isset($files_by_site[$site_hash]) || $timestamp > $files_by_site[$site_hash]['timestamp']) {
                        $files_by_site[$site_hash] = array(
                            'key' => $key,
                            'type' => 'diagnostic',
                            'size' => $object['Size'],
                            'last_modified' => $object['LastModified'],
                            'timestamp' => $timestamp,
                            'site_hash' => $site_hash
                        );
                    }
                } else {
                    // Process as individual file if we can't extract site hash
                    $queue[] = array(
                        'key' => $key,
                        'type' => 'diagnostic',
                        'size' => $object['Size'],
                        'last_modified' => $object['LastModified']
                    );
                }
            }
        }
        
        // Add bug reports to queue
        foreach ($bug_report_files as $file) {
            $queue[] = $file;
        }
        
        // Add diagnostic files to queue
        foreach ($files_by_site as $file) {
            $queue[] = $file;
        }
        
        return $queue;
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
                }
                
                // Mark as processed
                WPSTB_Database::mark_file_processed($key, md5($content));
                $progress['processed_files']++;
                
                WPSTB_Utilities::log('Bulk scan: Successfully processed ' . $key);
                
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