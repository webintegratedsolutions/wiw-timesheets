<?php
/**
 * Controller for the Settings Page
 * Location: admin/pages/settings-main.php
 */

if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.'));
}
?>
<div class="wrap">
    <h1>WIW Timesheet Manager Settings</h1>
    <form method="post" action="options.php">
        <?php
        settings_fields('wiw_timesheets_settings_group');
        do_settings_sections('wiw-timesheets-settings');
        submit_button();
        ?>
    </form>
    
    <hr>
    <h3>Debug Info</h3>
    <p>Use this area to test API connectivity or view the current token status.</p>
</div>
