<?php
if (!defined('ABSPATH')) exit;

class WPSTB_Hosting_Providers {
    
    private $providers_url = 'https://assets.wpspeedtestpro.com/wphostingproviders.json';
    
    public function __construct() {
        add_action('wp_ajax_wpstb_update_providers', array($this, 'ajax_update_providers'));
        add_action('wp_ajax_wpstb_clear_providers_cache', array($this, 'ajax_clear_providers_cache'));
        
        // Schedule automatic updates
        if (!wp_next_scheduled('wpstb_update_hosting_providers')) {
            wp_schedule_event(time(), 'daily', 'wpstb_update_hosting_providers');
        }
        
        add_action('wpstb_update_hosting_providers', array($this, 'update_providers_cache'));
    }
    
    public function ajax_update_providers() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        try {
            $result = $this->update_providers_cache();
            if ($result) {
                wp_send_json_success('Hosting providers updated successfully');
            } else {
                wp_send_json_error('Failed to update hosting providers');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_clear_providers_cache() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        global $wpdb;
        $table = $wpdb->prefix . 'wpstb_hosting_providers';
        $wpdb->query("TRUNCATE TABLE $table");
        
        delete_option('wpstb_hosting_providers_last_update');
        
        wp_send_json_success('Hosting providers cache cleared');
    }
    
    public function update_providers_cache() {
        try {
            $response = wp_remote_get($this->providers_url, array(
                'timeout' => 30,
                'headers' => array(
                    'User-Agent' => 'WPSpeedTest-Browser/' . WPSTB_VERSION
                )
            ));
            
            if (is_wp_error($response)) {
                throw new Exception('Failed to fetch providers: ' . $response->get_error_message());
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON response');
            }
            
            if (!isset($data['providers']) || !is_array($data['providers'])) {
                throw new Exception('Invalid providers data structure');
            }
            
            // Update database
            WPSTB_Database::update_hosting_providers($data['providers']);
            
            // Update last update time
            update_option('wpstb_hosting_providers_last_update', current_time('mysql'));
            
            return true;
            
        } catch (Exception $e) {
            error_log('WPSTB: Failed to update hosting providers: ' . $e->getMessage());
            return false;
        }
    }
    
    public function get_provider_name($provider_id) {
        if (empty($provider_id)) {
            return 'Unknown';
        }
        
        $provider = WPSTB_Database::get_hosting_provider($provider_id);
        return $provider ? $provider->name : 'Unknown Provider';
    }
    
    public function get_package_name($provider_id, $package_id) {
        if (empty($provider_id) || empty($package_id)) {
            return 'Unknown';
        }
        
        $provider = WPSTB_Database::get_hosting_provider($provider_id);
        if (!$provider || !$provider->packages) {
            return 'Unknown Package';
        }
        
        foreach ($provider->packages as $package) {
            if ($package['Package_ID'] === $package_id) {
                return $package['type'];
            }
        }
        
        return 'Unknown Package';
    }
    
    public static function get_last_update_time() {
        return get_option('wpstb_hosting_providers_last_update', '');
    }
} 