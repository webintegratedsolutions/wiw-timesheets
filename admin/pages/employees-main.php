<?php
/**
 * Controller for the Employees Page
 * Location: admin/pages/employees-main.php
 */

if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.'));
}

$users = $this->get_wiw_users(); // Replace with your method for fetching users

?>
<div class="wrap">
    <h1>When I Work Employees</h1>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>WIW ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo esc_html($user->id); ?></td>
                    <td><?php echo esc_html($user->first_name . ' ' . $user->last_name); ?></td>
                    <td><?php echo esc_html($user->email); ?></td>
                    <td><?php echo esc_html($user->role == 3 ? 'Admin' : 'Employee'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
