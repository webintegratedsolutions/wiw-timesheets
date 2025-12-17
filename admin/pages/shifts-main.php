<?php
/**
 * Controller for the Shifts Page
 * Location: admin/pages/shifts-main.php
 */

if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.'));
}

// Data fetching for shifts
$shifts_response = $this->get_shifts_data_from_api(); // Replace with your actual method name
$shifts = $shifts_response['shifts'] ?? [];

?>
<div class="wrap">
    <h1>When I Work Shifts</h1>
    <p>This page displays all scheduled shifts fetched from the API.</p>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Shift ID</th>
                <th>Employee</th>
                <th>Location</th>
                <th>Start Time</th>
                <th>End Time</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($shifts)): ?>
                <tr><td colspan="5">No shifts found.</td></tr>
            <?php else: ?>
                <?php foreach ($shifts as $shift): ?>
                    <tr>
                        <td><?php echo esc_html($shift->id); ?></td>
                        <td><?php echo esc_html($this->get_employee_name($shift->user_id)); ?></td>
                        <td><?php echo esc_html($this->get_location_name($shift->location_id)); ?></td>
                        <td><?php echo esc_html($shift->start_time); ?></td>
                        <td><?php echo esc_html($shift->end_time); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
