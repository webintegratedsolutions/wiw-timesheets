<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
    <h1>🗓️ When I Work Timesheet Dashboard</h1>
    <table class="wp-list-table widefat fixed striped" id="wiw-timesheets-table">
        <thead>
            <tr>
                <th>ID</th><th>Date</th><th>Employee</th><th>Location</th>
                <th>Clock In</th><th>Clock Out</th><th>Status</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php include WIW_PLUGIN_PATH . 'admin/timesheet-loop.php'; ?>
        </tbody>
    </table>
</div>
