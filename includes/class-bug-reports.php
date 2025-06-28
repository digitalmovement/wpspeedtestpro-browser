<?php
if (!defined('ABSPATH')) exit;

class WPSTB_Bug_Reports {
    
    public function __construct() {
        add_action('wp_ajax_wpstb_get_bug_report', array($this, 'ajax_get_bug_report'));
        add_action('wp_ajax_wpstb_update_bug_notes', array($this, 'ajax_update_bug_notes'));
    }
    
    public function ajax_get_bug_report() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        $id = intval($_POST['id']);
        $report = WPSTB_Database::get_bug_report($id);
        
        if ($report) {
            wp_send_json_success($report);
        } else {
            wp_send_json_error('Bug report not found');
        }
    }
    
    public function ajax_update_bug_notes() {
        check_ajax_referer('wpstb_nonce', 'nonce');
        
        $id = intval($_POST['id']);
        $notes = sanitize_textarea_field($_POST['notes']);
        
        $result = WPSTB_Database::update_bug_report_status($id, null, $notes);
        
        if ($result) {
            wp_send_json_success('Notes updated');
        } else {
            wp_send_json_error('Failed to update notes');
        }
    }
    
    public static function get_status_options() {
        return array(
            'open' => 'Open',
            'in_progress' => 'In Progress',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
            'wont_fix' => "Won't Fix"
        );
    }
    
    public static function get_priority_class($priority) {
        $classes = array(
            'low' => 'priority-low',
            'medium' => 'priority-medium',
            'high' => 'priority-high',
            'critical' => 'priority-critical'
        );
        
        return isset($classes[$priority]) ? $classes[$priority] : 'priority-medium';
    }
} 