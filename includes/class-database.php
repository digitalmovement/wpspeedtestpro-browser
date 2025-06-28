<?php
/**
 * Database management class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSTB_Database {
    
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Bug reports table
        $bug_reports_table = $wpdb->prefix . 'wpstb_bug_reports';
        $sql_bug_reports = "CREATE TABLE $bug_reports_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            site_key varchar(255) NOT NULL,
            report_id varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            message text,
            priority varchar(50),
            severity varchar(50),
            status varchar(50) DEFAULT 'open',
            steps_to_reproduce text,
            expected_behavior text,
            actual_behavior text,
            frequency varchar(100),
            environment_os varchar(100),
            environment_browser varchar(100),
            environment_device varchar(100),
            wp_version varchar(20),
            php_version varchar(20),
            site_url varchar(255),
            plugin_version varchar(20),
            current_theme varchar(255),
            timestamp datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            admin_notes text,
            PRIMARY KEY (id),
            UNIQUE KEY unique_report (site_key, report_id),
            KEY idx_status (status),
            KEY idx_priority (priority),
            KEY idx_severity (severity)
        ) $charset_collate;";
        
        // Diagnostic data table
        $diagnostic_table = $wpdb->prefix . 'wpstb_diagnostic_data';
        $sql_diagnostic = "CREATE TABLE $diagnostic_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            site_key varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            site_url varchar(255),
            wp_version varchar(20),
            php_version varchar(20),
            mysql_version varchar(100),
            server_software varchar(100),
            os varchar(100),
            memory_limit varchar(20),
            max_execution_time varchar(20),
            hosting_provider_id int(11),
            hosting_package_id varchar(20),
            country varchar(10),
            region varchar(100),
            city varchar(100),
            timestamp datetime,
            processed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_file (file_path),
            KEY idx_site_key (site_key),
            KEY idx_hosting_provider (hosting_provider_id),
            KEY idx_country (country)
        ) $charset_collate;";
        
        // Plugin data table
        $plugins_table = $wpdb->prefix . 'wpstb_site_plugins';
        $sql_plugins = "CREATE TABLE $plugins_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            diagnostic_id bigint(20) NOT NULL,
            plugin_name varchar(255) NOT NULL,
            plugin_version varchar(50),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_diagnostic_id (diagnostic_id),
            KEY idx_plugin_name (plugin_name),
            FOREIGN KEY (diagnostic_id) REFERENCES $diagnostic_table(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Processed files table
        $processed_files_table = $wpdb->prefix . 'wpstb_processed_files';
        $sql_processed_files = "CREATE TABLE $processed_files_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            file_path varchar(500) NOT NULL,
            file_hash varchar(255),
            processed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_file_path (file_path),
            KEY idx_processed_at (processed_at)
        ) $charset_collate;";
        
        // Hosting providers cache table
        $hosting_providers_table = $wpdb->prefix . 'wpstb_hosting_providers';
        $sql_hosting_providers = "CREATE TABLE $hosting_providers_table (
            id int(11) NOT NULL,
            name varchar(255) NOT NULL,
            packages text,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_bug_reports);
        dbDelta($sql_diagnostic);
        dbDelta($sql_plugins);
        dbDelta($sql_processed_files);
        dbDelta($sql_hosting_providers);
    }
    
    public static function get_bug_reports($status = '', $limit = 20, $offset = 0) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_bug_reports';
        $where = '';
        
        if (!empty($status)) {
            $where = $wpdb->prepare(" WHERE status = %s", $status);
        }
        
        $query = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
        return $wpdb->get_results($wpdb->prepare($query, $limit, $offset));
    }
    
    public static function get_bug_report($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_bug_reports';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }
    
    public static function update_bug_report_status($id, $status, $notes = '') {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_bug_reports';
        $data = array('status' => $status);
        
        if (!empty($notes)) {
            $data['admin_notes'] = $notes;
        }
        
        return $wpdb->update($table, $data, array('id' => $id));
    }
    
    public static function insert_bug_report($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_bug_reports';
        return $wpdb->insert($table, $data);
    }
    
    public static function insert_diagnostic_data($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_diagnostic_data';
        return $wpdb->insert($table, $data);
    }
    
    public static function insert_site_plugin($diagnostic_id, $plugin_name, $plugin_version) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_site_plugins';
        return $wpdb->insert($table, array(
            'diagnostic_id' => $diagnostic_id,
            'plugin_name' => $plugin_name,
            'plugin_version' => $plugin_version
        ));
    }
    
    public static function mark_file_processed($file_path, $file_hash = '') {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_processed_files';
        return $wpdb->insert($table, array(
            'file_path' => $file_path,
            'file_hash' => $file_hash
        ));
    }
    
    public static function is_file_processed($file_path) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_processed_files';
        $result = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE file_path = %s", $file_path));
        return $result > 0;
    }
    
    public static function get_analytics_data() {
        global $wpdb;
        
        $diagnostic_table = $wpdb->prefix . 'wpstb_diagnostic_data';
        $plugins_table = $wpdb->prefix . 'wpstb_site_plugins';
        
        $data = array();
        
        // Total sites
        $data['total_sites'] = $wpdb->get_var("SELECT COUNT(DISTINCT site_key) FROM $diagnostic_table");
        
        // WordPress versions
        $data['wp_versions'] = $wpdb->get_results("
            SELECT wp_version, COUNT(*) as count 
            FROM $diagnostic_table 
            WHERE wp_version IS NOT NULL 
            GROUP BY wp_version 
            ORDER BY count DESC
        ");
        
        // PHP versions
        $data['php_versions'] = $wpdb->get_results("
            SELECT php_version, COUNT(*) as count 
            FROM $diagnostic_table 
            WHERE php_version IS NOT NULL 
            GROUP BY php_version 
            ORDER BY count DESC
        ");
        
        // Hosting providers
        $data['hosting_providers'] = $wpdb->get_results("
            SELECT hosting_provider_id, COUNT(*) as count 
            FROM $diagnostic_table 
            WHERE hosting_provider_id IS NOT NULL 
            GROUP BY hosting_provider_id 
            ORDER BY count DESC
        ");
        
        // Countries
        $data['countries'] = $wpdb->get_results("
            SELECT country, COUNT(*) as count 
            FROM $diagnostic_table 
            WHERE country IS NOT NULL 
            GROUP BY country 
            ORDER BY count DESC
        ");
        
        // Most used plugins
        $data['popular_plugins'] = $wpdb->get_results("
            SELECT plugin_name, COUNT(*) as count 
            FROM $plugins_table 
            GROUP BY plugin_name 
            ORDER BY count DESC 
            LIMIT 20
        ");
        
        return $data;
    }
    
    public static function get_all_sites() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_diagnostic_data';
        return $wpdb->get_results("
            SELECT DISTINCT site_url, site_key, wp_version, php_version, country, region, city
            FROM $table 
            WHERE site_url IS NOT NULL 
            ORDER BY site_url
        ");
    }
    
    public static function update_hosting_providers($providers) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_hosting_providers';
        
        // Clear existing data
        $wpdb->query("TRUNCATE TABLE $table");
        
        // Insert new data
        foreach ($providers as $provider) {
            $wpdb->insert($table, array(
                'id' => $provider['id'],
                'name' => $provider['name'],
                'packages' => json_encode($provider['packages'])
            ));
        }
    }
    
    public static function get_hosting_provider($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wpstb_hosting_providers';
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        
        if ($result) {
            $result->packages = json_decode($result->packages, true);
        }
        
        return $result;
    }
} 