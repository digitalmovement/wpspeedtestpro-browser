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
        
        echo '<div class="wrap">';
        echo '<h1>SpeedTest Browser Dashboard</h1>';
        
        if ($last_scan) {
            echo '<p><strong>Last Scan:</strong> ' . $last_scan . '</p>';
        }
        
        echo '<div class="wpstb-stats-grid">';
        echo '<div class="wpstb-stat-box"><h3>' . $analytics['total_sites'] . '</h3><p>Total Sites</p></div>';
        echo '<div class="wpstb-stat-box"><h3>' . count($analytics['wp_versions']) . '</h3><p>WP Versions</p></div>';
        echo '<div class="wpstb-stat-box"><h3>' . count($analytics['countries']) . '</h3><p>Countries</p></div>';
        echo '</div>';
        
        echo '<button id="scan-bucket" class="button button-primary">Scan S3 Bucket</button>';
        echo '<div id="scan-results"></div>';
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
        echo '<div id="diagnostics-result"></div>';
        
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
} 