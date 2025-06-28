<?php
/**
 * Utilities class for common helper functions
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSTB_Utilities {
    
    /**
     * Format file size in human readable format
     */
    public static function format_file_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Format timestamp for display
     */
    public static function format_timestamp($timestamp) {
        if (empty($timestamp)) {
            return 'N/A';
        }
        
        $date = new DateTime($timestamp);
        return $date->format('M j, Y g:i A');
    }
    
    /**
     * Get priority badge HTML
     */
    public static function get_priority_badge($priority) {
        $priority = strtolower($priority);
        $class = 'priority-badge priority-' . $priority;
        return '<span class="' . $class . '">' . ucfirst($priority) . '</span>';
    }
    
    /**
     * Get status badge HTML
     */
    public static function get_status_badge($status) {
        $status = strtolower($status);
        $class = 'status-badge status-' . str_replace(' ', '_', $status);
        $display = ucwords(str_replace('_', ' ', $status));
        return '<span class="' . $class . '">' . $display . '</span>';
    }
    
    /**
     * Sanitize and validate S3 endpoint URL
     */
    public static function validate_s3_endpoint($endpoint) {
        $endpoint = esc_url_raw($endpoint);
        
        if (empty($endpoint)) {
            return array('valid' => false, 'message' => 'Endpoint URL is required');
        }
        
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return array('valid' => false, 'message' => 'Invalid URL format');
        }
        
        $parsed = parse_url($endpoint);
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], array('http', 'https'))) {
            return array('valid' => false, 'message' => 'URL must use http or https protocol');
        }
        
        return array('valid' => true, 'endpoint' => $endpoint);
    }
    
    /**
     * Generate CSV export for bug reports
     */
    public static function export_bug_reports_csv($reports) {
        $filename = 'speedtest-bug-reports-' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, array(
            'ID', 'Site URL', 'Email', 'Priority', 'Severity', 'Status',
            'Message', 'WordPress Version', 'PHP Version', 'Theme',
            'Created Date', 'Admin Notes'
        ));
        
        // CSV data
        foreach ($reports as $report) {
            fputcsv($output, array(
                $report->id,
                $report->site_url,
                $report->email,
                $report->priority,
                $report->severity,
                $report->status,
                $report->message,
                $report->wp_version,
                $report->php_version,
                $report->current_theme,
                $report->created_at,
                $report->admin_notes
            ));
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Generate CSV export for analytics data
     */
    public static function export_analytics_csv($sites) {
        $filename = 'speedtest-analytics-' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, array(
            'Site URL', 'WordPress Version', 'PHP Version', 'Country', 
            'Region', 'City', 'Last Seen'
        ));
        
        // CSV data
        foreach ($sites as $site) {
            fputcsv($output, array(
                $site->site_url,
                $site->wp_version,
                $site->php_version,
                $site->country,
                $site->region,
                $site->city,
                $site->processed_at
            ));
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Log plugin activities
     */
    public static function log($message, $level = 'info') {
        if (WP_DEBUG && WP_DEBUG_LOG) {
            $log_message = sprintf('[WPSTB][%s] %s', strtoupper($level), $message);
            error_log($log_message);
        }
    }
    
    /**
     * Check if current user can access plugin
     */
    public static function current_user_can_access() {
        return current_user_can('manage_options');
    }
    
    /**
     * Get plugin version
     */
    public static function get_plugin_version() {
        return WPSTB_VERSION;
    }
    
    /**
     * Get database table names with prefix
     */
    public static function get_table_names() {
        global $wpdb;
        
        return array(
            'bug_reports' => $wpdb->prefix . 'wpstb_bug_reports',
            'diagnostic_data' => $wpdb->prefix . 'wpstb_diagnostic_data',
            'site_plugins' => $wpdb->prefix . 'wpstb_site_plugins',
            'processed_files' => $wpdb->prefix . 'wpstb_processed_files',
            'hosting_providers' => $wpdb->prefix . 'wpstb_hosting_providers'
        );
    }
    
    /**
     * Clean up old processed files (older than 30 days)
     */
    public static function cleanup_old_processed_files() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_processed_files';
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE processed_at < %s",
            $thirty_days_ago
        ));
        
        self::log("Cleaned up $deleted old processed file records");
        
        return $deleted;
    }
    
    /**
     * Get system information for debugging
     */
    public static function get_system_info() {
        global $wp_version, $wpdb;
        
        return array(
            'plugin_version' => WPSTB_VERSION,
            'wordpress_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'mysql_version' => $wpdb->db_version(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size')
        );
    }
    
    /**
     * Validate JSON data structure
     */
    public static function validate_json_structure($data, $type) {
        if (!is_array($data)) {
            return false;
        }
        
        switch ($type) {
            case 'bug_report':
                return isset($data['siteKey']) && isset($data['report']) && isset($data['timestamp']);
                
            case 'diagnostic':
                return isset($data['clientInfo']) && isset($data['timestamp']);
                
            default:
                return false;
        }
    }
    
    /**
     * Generate nonce for AJAX requests
     */
    public static function generate_nonce($action = 'wpstb_nonce') {
        return wp_create_nonce($action);
    }
    
    /**
     * Verify nonce for AJAX requests
     */
    public static function verify_nonce($nonce, $action = 'wpstb_nonce') {
        return wp_verify_nonce($nonce, $action);
    }
} 