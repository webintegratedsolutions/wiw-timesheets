<?php
/**
 * Controller for the Locations Page
 * Location: admin/pages/locations-main.php
 */

if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.'));
}

// Fetch locations from the API
$locations_data = $this->get_wiw_locations(); // Adjust to your actual method name
$locations = $locations_data['locations'] ?? [];

?>
<div class="wrap">
    <h1>When I Work Locations</h1>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Location ID</th>
                <th>Location Name</th>
                <th>Address</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($locations)): ?>
                <tr><td colspan="3">No locations found.</td></tr>
            <?php else: ?>
                <?php foreach ($locations as $loc): ?>
                    <tr>
                        <td><?php echo esc_html($loc->id); ?></td>
                        <td><?php echo esc_html($loc->name); ?></td>
                        <td><?php echo esc_html($loc->address ?? 'N/A'); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
