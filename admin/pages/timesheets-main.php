<?php
if (!defined('ABSPATH')) exit;

// Fetch data through the API handler property of the main class
$timesheet_response = $this->api->get_timesheet_data(); 
$user_map           = $this->api->get_employee_user_map();

// Variables for the view
$wp_timezone      = wp_timezone();
$time_format      = get_option('time_format') ?: 'g:i A';
$timesheet_nonce  = wp_create_nonce('wiw_timesheet_nonce');
$employee_data    = $timesheet_response->times ?? [];

// Load UI parts
include WIW_PLUGIN_PATH . 'admin/timesheet-view.php';
include WIW_PLUGIN_PATH . 'admin/timesheet-dashboard-script.php';
