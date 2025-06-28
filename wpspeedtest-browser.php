<?php
/**
 * Plugin Name: WP SpeedTest Browser
 * Plugin URI: https://wpspeedtestpro.com
 * Description: A comprehensive plugin to manage and analyze SpeedTest Pro diagnostic data and bug reports from S3 bucket.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wpspeedtest-browser
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPSTB_VERSION', '1.0.0');
define('WPSTB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPSTB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPSTB_PLUGIN_FILE', __FILE__);

// Main plugin class
class WPSpeedTestBrowser {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Load text domain
        load_plugin_textdomain('wpspeedtest-browser', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Include required files
        $this->includes();
        
        // Initialize components
        $this->init_components();
    }
    
    private function includes() {
        require_once WPSTB_PLUGIN_DIR . 'includes/class-database.php';
        require_once WPSTB_PLUGIN_DIR . 'includes/class-utilities.php';
        require_once WPSTB_PLUGIN_DIR . 'includes/class-s3-connector.php';
        require_once WPSTB_PLUGIN_DIR . 'includes/class-admin.php';
        require_once WPSTB_PLUGIN_DIR . 'includes/class-bug-reports.php';
        require_once WPSTB_PLUGIN_DIR . 'includes/class-analytics.php';
        require_once WPSTB_PLUGIN_DIR . 'includes/class-hosting-providers.php';
    }
    
    private function init_components() {
        new WPSTB_Admin();
        new WPSTB_Bug_Reports();
        new WPSTB_Analytics();
        new WPSTB_Hosting_Providers();
    }
    
    public function activate() {
        // Create database tables
        WPSTB_Database::create_tables();
        
        // Set default options
        add_option('wpstb_version', WPSTB_VERSION);
        add_option('wpstb_s3_endpoint', '');
        add_option('wpstb_s3_access_key', '');
        add_option('wpstb_s3_secret_key', '');
        add_option('wpstb_s3_bucket', '');
        add_option('wpstb_last_scan', '');
        add_option('wpstb_hosting_providers_cache', '');
        add_option('wpstb_hosting_providers_last_update', '');
    }
    
    public function deactivate() {
        // Clean up scheduled events
        wp_clear_scheduled_hook('wpstb_update_hosting_providers');
    }
}

// Initialize plugin
new WPSpeedTestBrowser(); 