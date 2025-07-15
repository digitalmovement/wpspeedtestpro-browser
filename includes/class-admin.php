<?php
if (!defined('ABSPATH')) exit;

class WPSTB_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_wpstb_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_wpstb_scan_bucket', array($this, 'ajax_scan_bucket'));
        add_action('wp_ajax_wpstb_update_bug_status', array($this, 'ajax_update_bug_status'));
        add_action('wp_ajax_wpstb_run_diagnostics', array($this, 'ajax_run_diagnostics'));
        add_action('wp_ajax_wpstb_clear_processed_files', array($this, 'ajax_clear_processed_files'));
        add_action('wp_ajax_wpstb_clear_all_data', array($this, 'ajax_clear_all_data'));
        add_action('wp_ajax_wpstb_reset_database', array($this, 'ajax_reset_database'));
        add_action('wp_ajax_wpstb_debug_s3_files', array($this, 'ajax_debug_s3_files'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'SpeedTest Browser',
            'SpeedTest Browser',
            'manage_options',
            'wpstb-dashboard',
            array($this, 'dashboard_page'),
            'dashicons-analytics',
            30
        );
        
        add_submenu_page(
            'wpstb-dashboard',
            'Bug Reports',
            'Bug Reports',
            'manage_options',
            'wpstb-bug-reports',
            array($this, 'bug_reports_page')
        );
        
        add_submenu_page(
            'wpstb-dashboard',
            'Analytics',
            'Analytics',
            'manage_options',
            'wpstb-analytics',
            array($this, 'analytics_page')
        );
        
        add_submenu_page(
            'wpstb-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'wpstb-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'wpstb-dashboard',
            'Debug Analyzer',
            'Debug Analyzer',
            'manage_options',
            'wpstb-debug-analyzer',
            array($this, 'debug_analyzer_page')
        );
    }
    
    public function admin_init() {
        register_setting('wpstb_settings', 'wpstb_s3_endpoint');
        register_setting('wpstb_settings', 'wpstb_s3_access_key');
        register_setting('wpstb_settings', 'wpstb_s3_secret_key');
        register_setting('wpstb_settings', 'wpstb_s3_bucket');
    }
    
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'wpstb') !== false) {
            wp_enqueue_script('wpstb-admin', WPSTB_PLUGIN_URL . 'assets/admin.js', array('jquery'), WPSTB_VERSION, true);
            wp_enqueue_style('wpstb-admin', WPSTB_PLUGIN_URL . 'assets/admin.css', array(), WPSTB_VERSION);
            wp_localize_script('wpstb-admin', 'wpstb_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wpstb_nonce')
            ));
        }
    }
    
    public function dashboard_page() {
        $last_scan = get_option('wpstb_last_scan', '');
        $analytics = WPSTB_Database::get_analytics_data();
        $bug_reports_count = WPSTB_Database::get_bug_reports_count();
        
        echo '<div class="wrap">';
        echo '<h1>SpeedTest Browser Dashboard</h1>';
        
        if ($last_scan) {
            echo '<p><strong>Last Scan:</strong> ' . $last_scan . '</p>';
        }
        
        echo '<div class="wpstb-stats-grid">';
        echo '<div class="wpstb-stat-box"><h3>' . $analytics['total_sites'] . '</h3><p>Total Sites</p></div>';
        echo '<div class="wpstb-stat-box"><h3>' . $bug_reports_count . '</h3><p>Bug Reports</p></div>';
        echo '<div class="wpstb-stat-box"><h3>' . count($analytics['wp_versions']) . '</h3><p>WP Versions</p></div>';
        echo '<div class="wpstb-stat-box"><h3>' . count($analytics['countries']) . '</h3><p>Countries</p></div>';
        echo '</div>';
        
        // Debug section
        if (WP_DEBUG) {
            $debug_data = WPSTB_Database::debug_bug_reports();
            echo '<div class="wpstb-debug-panel" style="background: #f0f0f1; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4;">';
            echo '<h3>Debug Information</h3>';
            echo '<p><strong>Bug Reports in Database:</strong> ' . $debug_data['count'] . '</p>';
            if (!empty($debug_data['recent'])) {
                echo '<p><strong>Most Recent Bug Report:</strong> ' . $debug_data['recent'][0]->created_at . ' - ' . esc_html($debug_data['recent'][0]->site_url) . '</p>';
            }
            echo '</div>';
        }
        
        echo '<button id="scan-bucket" class="button button-primary">Scan S3 Bucket</button>';
        echo '<div id="scan-results"></div>';
        
        // Bulk Scanner Section
        echo '<div class="wpstb-bulk-scanner-section" style="margin-top: 30px;">';
        echo '<h2>Bulk Scanner</h2>';
        echo '<p>Process large numbers of files without timeouts. The bulk scanner processes files in small batches and can be paused/resumed.</p>';
        
        // Get bulk scanner progress
        $bulk_scanner = new WPSTB_Bulk_Scanner();
        $bulk_progress = $bulk_scanner->get_scan_statistics();
        
        if ($bulk_progress) {
            echo '<div class="wpstb-bulk-status">';
            echo '<h3>Current Bulk Scan Status</h3>';
            echo '<div class="wpstb-progress-info">';
            echo '<p><strong>Status:</strong> <span id="bulk-status">' . ucfirst($bulk_progress['status']) . '</span></p>';
            echo '<p><strong>Progress:</strong> <span id="bulk-progress">' . $bulk_progress['processed_files'] . ' / ' . $bulk_progress['total_files'] . ' files (' . $bulk_progress['percentage'] . '%)</span></p>';
            echo '<p><strong>Bug Reports:</strong> <span id="bulk-bug-reports">' . $bulk_progress['bug_reports'] . '</span></p>';
            echo '<p><strong>Diagnostic Files:</strong> <span id="bulk-diagnostic-files">' . $bulk_progress['diagnostic_files'] . '</span></p>';
            echo '<p><strong>Errors:</strong> <span id="bulk-errors">' . $bulk_progress['errors'] . '</span></p>';
            echo '<p><strong>Last Update:</strong> <span id="bulk-last-update">' . $bulk_progress['last_update'] . '</span></p>';
            echo '</div>';
            
            // Progress bar
            echo '<div class="wpstb-progress-bar" style="width: 100%; height: 20px; background-color: #f0f0f1; border: 1px solid #ccd0d4; margin: 10px 0;">';
            echo '<div class="wpstb-progress-fill" id="bulk-progress-fill" style="width: ' . $bulk_progress['percentage'] . '%; height: 100%; background-color: #0073aa;"></div>';
            echo '</div>';
            echo '</div>';
        }
        
        // Control buttons
        echo '<div class="wpstb-bulk-controls">';
        echo '<button id="start-bulk-scan" class="button button-primary">Start Bulk Scan</button>';
        echo '<button id="resume-bulk-scan" class="button button-secondary" style="display: none;">Resume Scan</button>';
        echo '<button id="pause-bulk-scan" class="button button-secondary" style="display: none;">Pause Scan</button>';
        echo '<button id="cancel-bulk-scan" class="button button-secondary" style="display: none;">Cancel Scan</button>';
        echo '</div>';
        
        echo '<div id="bulk-scan-results" style="margin-top: 15px;"></div>';
        echo '</div>';
        
        echo '</div>';
    }
    
    public function bug_reports_page() {
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $reports = WPSTB_Database::get_bug_reports($status, 20, 0);
        
        echo '<div class="wrap">';
        echo '<h1>Bug Reports</h1>';
        
        echo '<ul class="subsubsub">';
        echo '<li><a href="?page=wpstb-bug-reports" class="' . (empty($status) ? 'current' : '') . '">All</a> |</li>';
        echo '<li><a href="?page=wpstb-bug-reports&status=open" class="' . ($status === 'open' ? 'current' : '') . '">Open</a> |</li>';
        echo '<li><a href="?page=wpstb-bug-reports&status=resolved" class="' . ($status === 'resolved' ? 'current' : '') . '">Resolved</a></li>';
        echo '</ul>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Site</th><th>Priority</th><th>Status</th><th>Message</th><th>Date</th><th>Actions</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($reports as $report) {
            $priority_class = 'priority-' . $report->priority;
            echo '<tr class="' . $priority_class . '">';
            echo '<td><a href="' . esc_url($report->site_url) . '" target="_blank">' . esc_html($report->site_url) . '</a></td>';
            echo '<td><span class="priority-badge priority-' . $report->priority . '">' . ucfirst($report->priority) . '</span></td>';
            echo '<td><span class="status-badge status-' . $report->status . '">' . ucfirst($report->status) . '</span></td>';
            echo '<td>' . esc_html(substr($report->message, 0, 100)) . '...</td>';
            echo '<td>' . date('M j, Y', strtotime($report->created_at)) . '</td>';
            echo '<td><button class="button view-report" data-id="' . $report->id . '">View</button></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }
    
    public function analytics_page() {
        $analytics = WPSTB_Database::get_analytics_data();
        $sites = WPSTB_Database::get_all_sites();
        
        echo '<div class="wrap">';
        echo '<h1>Analytics</h1>';
        
        echo '<div class="wpstb-charts-container">';
        
        // WordPress versions chart
        echo '<div class="wpstb-chart">';
        echo '<h3>WordPress Versions</h3>';
        echo '<canvas id="wp-versions-chart"></canvas>';
        echo '</div>';
        
        // PHP versions chart
        echo '<div class="wpstb-chart">';
        echo '<h3>PHP Versions</h3>';
        echo '<canvas id="php-versions-chart"></canvas>';
        echo '</div>';
        
        // Countries chart
        echo '<div class="wpstb-chart bar-chart">';
        echo '<h3>Countries</h3>';
        echo '<canvas id="countries-chart"></canvas>';
        echo '</div>';
        
        echo '</div>';
        
        // Sites list
        echo '<h3>All Sites (' . count($sites) . ')</h3>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Site URL</th><th>WordPress</th><th>PHP</th><th>Location</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($sites as $site) {
            echo '<tr>';
            echo '<td><a href="' . esc_url($site->site_url) . '" target="_blank">' . esc_html($site->site_url) . '</a></td>';
            echo '<td>' . esc_html($site->wp_version) . '</td>';
            echo '<td>' . esc_html($site->php_version) . '</td>';
            echo '<td>' . esc_html($site->city . ', ' . $site->country) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        // Pass data to JavaScript
        echo '<script>';
        echo 'var wpstb_analytics = ' . json_encode($analytics) . ';';
        echo '</script>';
        
        echo '</div>';
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            update_option('wpstb_s3_endpoint', sanitize_text_field($_POST['wpstb_s3_endpoint']));
            update_option('wpstb_s3_access_key', sanitize_text_field($_POST['wpstb_s3_access_key']));
            update_option('wpstb_s3_secret_key', sanitize_text_field($_POST['wpstb_s3_secret_key']));
            update_option('wpstb_s3_bucket', sanitize_text_field($_POST['wpstb_s3_bucket']));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $endpoint = get_option('wpstb_s3_endpoint', '');
        $access_key = get_option('wpstb_s3_access_key', '');
        $secret_key = get_option('wpstb_s3_secret_key', '');
        $bucket = get_option('wpstb_s3_bucket', '');
        
        echo '<div class="wrap">';
        echo '<h1>Settings</h1>';
        echo '<form method="post">';
        
        echo '<table class="form-table">';
        echo '<tr><th>S3 Endpoint</th><td><input type="text" name="wpstb_s3_endpoint" value="' . esc_attr($endpoint) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>Access Key</th><td><input type="text" name="wpstb_s3_access_key" value="' . esc_attr($access_key) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>Secret Key</th><td><input type="password" name="wpstb_s3_secret_key" value="' . esc_attr($secret_key) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>Bucket Name</th><td><input type="text" name="wpstb_s3_bucket" value="' . esc_attr($bucket) . '" class="regular-text" /></td></tr>';
        echo '</table>';
        
        submit_button();
        echo '</form>';
        
        echo '<button id="test-connection" class="button">Test Connection</button>';
        echo '<div id="connection-result"></div>';
        
        echo '<h3>Hosting Providers</h3>';
        $last_update = WPSTB_Hosting_Providers::get_last_update_time();
        if ($last_update) {
            echo '<p><strong>Last Updated:</strong> ' . $last_update . '</p>';
        } else {
            echo '<p><em>Hosting providers data not loaded</em></p>';
        }
        
        echo '<button id="update-providers" class="button">Update Providers</button> ';
        echo '<button id="clear-providers-cache" class="button">Clear Cache</button>';
        
        echo '<h3>Diagnostics</h3>';
        echo '<p>If you\'re having trouble with S3 connections, run diagnostics to see detailed information.</p>';
        echo '<button id="run-diagnostics" class="button">Run S3 Diagnostics</button>';
        echo '<button id="debug-s3-files" class="button" style="margin-left: 10px;">Debug S3 Files</button>';
        echo '<div id="diagnostics-result"></div>';
        echo '<div id="s3-files-debug"></div>';
        
        echo '<h3>Database Management</h3>';
        echo '<p>Use these tools to manage the downloaded data. <strong>Warning:</strong> These actions cannot be undone!</p>';
        
        echo '<div class="wpstb-database-actions" style="margin: 20px 0;">';
        echo '<button id="clear-processed-files" class="button button-secondary" style="margin-right: 10px;">Clear Processed Files List</button>';
        echo '<button id="clear-all-data" class="button button-secondary" style="margin-right: 10px;">Clear All Downloaded Data</button>';
        echo '<button id="reset-database" class="button button-secondary">Reset Entire Database</button>';
        echo '</div>';
        
        echo '<div class="wpstb-database-info" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin: 15px 0;">';
        echo '<h4>Database Status</h4>';
        $db_stats = WPSTB_Database::get_database_stats();
        echo '<p><strong>Processed Files:</strong> ' . $db_stats['processed_files'] . '</p>';
        echo '<p><strong>Bug Reports:</strong> ' . $db_stats['bug_reports'] . '</p>';
        echo '<p><strong>Diagnostic Records:</strong> ' . $db_stats['diagnostic_data'] . '</p>';
        echo '<p><strong>Plugin Records:</strong> ' . $db_stats['site_plugins'] . '</p>';
        echo '<p><strong>Hosting Providers:</strong> ' . $db_stats['hosting_providers'] . '</p>';
        echo '</div>';
        
        echo '<div id="database-action-result"></div>';
        
        echo '</div>';
    }
    
    public function debug_analyzer_page() {
        echo '<div class="wrap">';
        echo '<h1>Debug Analyzer</h1>';
        echo '<p>This tool helps debug file processing issues by allowing you to search for and analyze specific files in your S3 bucket.</p>';
        
        // Database Statistics Section
        echo '<div class="wpstb-debug-section" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">';
        echo '<h2>Database Statistics</h2>';
        echo '<p>Current database status and potential issues:</p>';
        echo '<button id="debug-get-db-stats" class="button button-primary">Get Database Stats</button>';
        echo '<div id="debug-db-stats-result"></div>';
        echo '</div>';
        
        // File Search Section
        echo '<div class="wpstb-debug-section" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">';
        echo '<h2>File Search</h2>';
        echo '<p>Search for specific files in your S3 bucket:</p>';
        
        echo '<div class="wpstb-search-form" style="margin-bottom: 20px;">';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th><label for="debug-search-term">Search Term:</label></th>';
        echo '<td><input type="text" id="debug-search-term" class="regular-text" placeholder="Enter filename or part of filename" /></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label for="debug-file-type">File Type:</label></th>';
        echo '<td>';
        echo '<select id="debug-file-type">';
        echo '<option value="all">All Files</option>';
        echo '<option value="diagnostic">Diagnostic Files</option>';
        echo '<option value="bug_report">Bug Reports</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '<button id="debug-search-files" class="button button-primary">Search Files</button>';
        echo '</div>';
        
        echo '<div id="debug-search-results"></div>';
        echo '</div>';
        
        // File Analysis Section
        echo '<div class="wpstb-debug-section" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">';
        echo '<h2>File Analysis</h2>';
        echo '<p>Analyze a specific file to see how it would be processed:</p>';
        
        echo '<div class="wpstb-analyze-form" style="margin-bottom: 20px;">';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th><label for="debug-file-key">File Key:</label></th>';
        echo '<td><input type="text" id="debug-file-key" class="regular-text" placeholder="Enter full file path/key" /></td>';
        echo '</tr>';
        echo '</table>';
        echo '<button id="debug-analyze-file" class="button button-primary">Analyze File</button>';
        echo '</div>';
        
        echo '<div id="debug-analyze-results"></div>';
        echo '</div>';
        
        // Recent Files Section
        echo '<div class="wpstb-debug-section" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">';
        echo '<h2>Recent Files</h2>';
        echo '<p>List of recent files with their processing status:</p>';
        echo '<button id="debug-list-files" class="button button-primary">List Recent Files</button>';
        echo '<div id="debug-files-list"></div>';
        echo '</div>';
        
        echo '</div>';
    }
    
    public function ajax_test_connection() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        $s3 = new WPSTB_S3_Connector();
        $result = $s3->test_connection();
        
        wp_send_json($result);
    }
    
    public function ajax_scan_bucket() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $s3 = new WPSTB_S3_Connector();
            $results = $s3->scan_bucket();
            
            // Add more detailed message
            $message = sprintf(
                'Scan completed! Found %d total objects, processed %d files (%d bug reports, %d diagnostic files), skipped %d, errors %d',
                $results['total_objects'],
                $results['processed'],
                $results['new_bug_reports'],
                $results['new_diagnostic_files'],
                $results['skipped'],
                $results['errors']
            );
            
            $results['message'] = $message;
            wp_send_json_success($results);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_update_bug_status() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        $id = intval($_POST['id']);
        $status = sanitize_text_field($_POST['status']);
        $notes = sanitize_textarea_field($_POST['notes']);
        
        $result = WPSTB_Database::update_bug_report_status($id, $status, $notes);
        
        if ($result) {
            wp_send_json_success('Bug report updated');
        } else {
            wp_send_json_error('Failed to update bug report');
        }
    }
    
    public function ajax_run_diagnostics() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        $diagnostics = array();
        
        // Check WordPress environment
        $diagnostics['wordpress'] = array(
            'version' => get_bloginfo('version'),
            'debug_mode' => WP_DEBUG ? 'Enabled' : 'Disabled',
            'debug_log' => WP_DEBUG_LOG ? 'Enabled' : 'Disabled'
        );
        
        // Check PHP environment
        $diagnostics['php'] = array(
            'version' => PHP_VERSION,
            'curl_enabled' => function_exists('curl_init') ? 'Yes' : 'No',
            'openssl_enabled' => extension_loaded('openssl') ? 'Yes' : 'No',
            'allow_url_fopen' => ini_get('allow_url_fopen') ? 'Yes' : 'No'
        );
        
        // Check S3 configuration
        $s3_config = array(
            'endpoint' => get_option('wpstb_s3_endpoint', ''),
            'access_key' => get_option('wpstb_s3_access_key', '') ? 'Set' : 'Not set',
            'secret_key' => get_option('wpstb_s3_secret_key', '') ? 'Set' : 'Not set',
            'bucket' => get_option('wpstb_s3_bucket', '')
        );
        
        $diagnostics['s3_config'] = $s3_config;
        
        // Test S3 connection
        if (!empty($s3_config['endpoint']) && $s3_config['access_key'] === 'Set' && $s3_config['secret_key'] === 'Set') {
            try {
                $s3 = new WPSTB_S3_Connector();
                $connection_test = $s3->test_connection();
                $diagnostics['s3_connection'] = $connection_test;
                
                if ($connection_test['success']) {
                    // Try to list a few objects for more details
                    try {
                        $objects = $s3->list_objects('', 5);
                        $diagnostics['s3_sample_objects'] = array_slice($objects, 0, 3);
                    } catch (Exception $e) {
                        $diagnostics['s3_sample_objects'] = 'Error: ' . $e->getMessage();
                    }
                }
            } catch (Exception $e) {
                $diagnostics['s3_connection'] = array(
                    'success' => false,
                    'message' => 'Exception: ' . $e->getMessage()
                );
            }
        } else {
            $diagnostics['s3_connection'] = array(
                'success' => false,
                'message' => 'S3 credentials not fully configured'
            );
        }
        
        // Check database tables
        global $wpdb;
        $tables = WPSTB_Utilities::get_table_names();
        $table_status = array();
        
        foreach ($tables as $name => $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
            $count = 0;
            if ($exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            }
            $table_status[$name] = array(
                'exists' => $exists,
                'count' => $count
            );
        }
        
        $diagnostics['database_tables'] = $table_status;
        
        wp_send_json_success($diagnostics);
    }
    
    public function ajax_clear_processed_files() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $result = WPSTB_Database::clear_processed_files();
            
            if ($result) {
                wp_send_json_success('Processed files list cleared successfully. You can now re-scan all files.');
            } else {
                wp_send_json_error('Failed to clear processed files list.');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    public function ajax_clear_all_data() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $result = WPSTB_Database::clear_all_data();
            
            if ($result) {
                wp_send_json_success('All downloaded data cleared successfully. Database is now empty.');
            } else {
                wp_send_json_error('Failed to clear all data.');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    public function ajax_reset_database() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $result = WPSTB_Database::reset_database();
            
            if ($result) {
                wp_send_json_success('Database reset successfully. All tables recreated and data cleared.');
            } else {
                wp_send_json_error('Failed to reset database.');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    public function ajax_debug_s3_files() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $s3 = new WPSTB_S3_Connector();
            
            // Get all files from the bucket (no limit) for comprehensive analysis
            $all_objects = $s3->list_objects('', 1000); // Get up to 1000 files
            
            // Also specifically search for bug-reports folder
            $bug_reports_objects = array();
            try {
                $bug_reports_objects = $s3->list_objects('bug-reports/', 1000);
            } catch (Exception $e) {
                // Bug reports folder might not exist, that's ok
            }
            
            // Combine and deduplicate
            $all_keys = array();
            $objects = array();
            
            foreach ($all_objects as $obj) {
                if (!in_array($obj['Key'], $all_keys)) {
                    $all_keys[] = $obj['Key'];
                    $objects[] = $obj;
                }
            }
            
            foreach ($bug_reports_objects as $obj) {
                if (!in_array($obj['Key'], $all_keys)) {
                    $all_keys[] = $obj['Key'];
                    $objects[] = $obj;
                }
            }
            
            $file_analysis = array(
                'total_files' => count($objects),
                'bug_report_files' => array(),
                'diagnostic_files' => array(),
                'other_files' => array(),
                'analysis' => array(),
                'folder_structure' => array(),
                'search_details' => array(
                    'total_objects_found' => count($all_objects),
                    'bug_reports_folder_search' => count($bug_reports_objects),
                    'combined_unique_files' => count($objects)
                )
            );
            
            foreach ($objects as $object) {
                $key = $object['Key'];
                
                // Analyze folder structure
                $path_parts = explode('/', $key);
                if (count($path_parts) > 1) {
                    $folder = $path_parts[0];
                    if (!isset($file_analysis['folder_structure'][$folder])) {
                        $file_analysis['folder_structure'][$folder] = 0;
                    }
                    $file_analysis['folder_structure'][$folder]++;
                }
                
                // Classify each file
                $is_json = preg_match('/\.json$/i', $key);
                $contains_bug_reports_slash = strpos($key, 'bug-reports/') !== false;
                $starts_with_bug_reports = strpos($key, 'bug-reports') === 0;
                $contains_bug_report_lower = strpos(strtolower($key), 'bug-report') !== false;
                $is_bug_report = $contains_bug_reports_slash || $starts_with_bug_reports || $contains_bug_report_lower;
                
                $file_info = array(
                    'key' => $key,
                    'size' => $object['Size'],
                    'modified' => $object['LastModified'],
                    'is_json' => $is_json,
                    'is_bug_report' => $is_bug_report,
                    'analysis' => array(
                        'contains_bug_reports_slash' => $contains_bug_reports_slash,
                        'starts_with_bug_reports' => $starts_with_bug_reports,
                        'contains_bug_report_lower' => $contains_bug_report_lower
                    )
                );
                
                if ($is_json && $is_bug_report) {
                    $file_analysis['bug_report_files'][] = $file_info;
                } elseif ($is_json) {
                    $file_analysis['diagnostic_files'][] = $file_info;
                } else {
                    $file_analysis['other_files'][] = $file_info;
                }
            }
            
            // Add summary analysis
            $file_analysis['analysis'] = array(
                'total_json_files' => count($file_analysis['bug_report_files']) + count($file_analysis['diagnostic_files']),
                'bug_reports_found' => count($file_analysis['bug_report_files']),
                'diagnostic_files_found' => count($file_analysis['diagnostic_files']),
                'non_json_files' => count($file_analysis['other_files'])
            );
            
            wp_send_json_success($file_analysis);
            
        } catch (Exception $e) {
            wp_send_json_error('Error debugging S3 files: ' . $e->getMessage());
        }
    }
} 