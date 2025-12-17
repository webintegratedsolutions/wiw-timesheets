<?php
/**
 * Plugin Name: When I Work Timesheets Manager
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) exit;
define('WIW_PLUGIN_PATH', plugin_dir_path(__FILE__));

// 1. Load the "Engines"
require_once WIW_PLUGIN_PATH . 'includes/wheniwork.php'; // Your API Client
require_once WIW_PLUGIN_PATH . 'includes/class-wiw-api-handler.php';
require_once WIW_PLUGIN_PATH . 'includes/class-wiw-ajax-handler.php';

class WIW_Timesheet_Manager {
    public $api;
    public $ajax;

    public function __construct() {
        // Initialize the sub-handlers
        $this->api  = new WIW_API_Handler();
        $this->ajax = new WIW_AJAX_Handler();

        add_action('admin_menu', [$this, 'add_admin_menus']);
        
        // Connect AJAX actions to the new AJAX handler class
        add_action('wp_ajax_wiw_edit_timesheet_hours', [$this->ajax, 'handle_save_hours']);
        add_action('wp_ajax_wiw_approve_period', [$this->ajax, 'handle_approve_period']);
    }

    public function add_admin_menus() {
        add_menu_page('Timesheets', 'WIW Timesheets', 'manage_options', 'wiw-timesheets', [$this, 'admin_timesheets_page'], 'dashicons-clock', 6);
        add_submenu_page('wiw-timesheets', 'Shifts', 'Shifts', 'manage_options', 'wiw-shifts', [$this, 'admin_shifts_page']);
        add_submenu_page('wiw-timesheets', 'Employees', 'Employees', 'manage_options', 'wiw-employees', [$this, 'admin_employees_page']);
        add_submenu_page('wiw-timesheets', 'Settings', 'Settings', 'manage_options', 'wiw-settings', [$this, 'admin_settings_page']);
    }

    // Router Methods: These point to the individual page files
    public function admin_timesheets_page() { include WIW_PLUGIN_PATH . 'admin/pages/timesheets-main.php'; }
    public function admin_shifts_page() { include WIW_PLUGIN_PATH . 'admin/pages/shifts-main.php'; }
    public function admin_employees_page() { include WIW_PLUGIN_PATH . 'admin/pages/employees-main.php'; }
    public function admin_settings_page() { include WIW_PLUGIN_PATH . 'admin/pages/settings-main.php'; }
}

new WIW_Timesheet_Manager();
