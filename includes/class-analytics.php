<?php
if (!defined('ABSPATH')) exit;

class WPSTB_Analytics {
    
    public function __construct() {
        add_action('wp_ajax_wpstb_get_chart_data', array($this, 'ajax_get_chart_data'));
    }
    
    public function ajax_get_chart_data() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        $type = sanitize_text_field($_POST['type']);
        $data = $this->get_chart_data($type);
        
        wp_send_json_success($data);
    }
    
    public function get_chart_data($type) {
        global $wpdb;
        
        $diagnostic_table = $wpdb->prefix . 'wpstb_diagnostic_data';
        
        switch ($type) {
            case 'wp_versions':
                return $wpdb->get_results("
                    SELECT wp_version as label, COUNT(*) as value 
                    FROM $diagnostic_table 
                    WHERE wp_version IS NOT NULL 
                    GROUP BY wp_version 
                    ORDER BY value DESC 
                    LIMIT 10
                ");
                
            case 'php_versions':
                return $wpdb->get_results("
                    SELECT php_version as label, COUNT(*) as value 
                    FROM $diagnostic_table 
                    WHERE php_version IS NOT NULL 
                    GROUP BY php_version 
                    ORDER BY value DESC 
                    LIMIT 10
                ");
                
            case 'countries':
                return $wpdb->get_results("
                    SELECT country as label, COUNT(*) as value 
                    FROM $diagnostic_table 
                    WHERE country IS NOT NULL 
                    GROUP BY country 
                    ORDER BY value DESC 
                    LIMIT 15
                ");
                
            case 'hosting_providers':
                $providers_table = $wpdb->prefix . 'wpstb_hosting_providers';
                return $wpdb->get_results("
                    SELECT hp.name as label, COUNT(*) as value 
                    FROM $diagnostic_table dd
                    LEFT JOIN $providers_table hp ON dd.hosting_provider_id = hp.id
                    WHERE dd.hosting_provider_id IS NOT NULL 
                    GROUP BY dd.hosting_provider_id 
                    ORDER BY value DESC 
                    LIMIT 10
                ");
                
            default:
                return array();
        }
    }
    
    public static function generate_color_palette($count) {
        $colors = array(
            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
            '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384'
        );
        
        $palette = array();
        for ($i = 0; $i < $count; $i++) {
            $palette[] = $colors[$i % count($colors)];
        }
        
        return $palette;
    }
} 