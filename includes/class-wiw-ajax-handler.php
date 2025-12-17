<?php
/**
 * Handles all AJAX requests from the Admin Dashboard
 */
if (!defined('ABSPATH')) exit;

class WIW_AJAX_Handler {
    
    public function handle_save_hours() {
        check_ajax_referer('wiw_timesheet_nonce', 'security');
        // ... (your logic to update When I Work)
        wp_send_json_success(['message' => 'Hours updated successfully!']);
    }

    public function handle_approve_period() {
        check_ajax_referer('wiw_timesheet_nonce', 'security');
        // ... (your logic to batch approve)
    }
}
