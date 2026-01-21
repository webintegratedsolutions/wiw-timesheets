<?php

// Include the data function for fetching timesheets
add_shortcode('wiw_client_portal', 'wiw_render_client_portal');

// Renders the client portal with timesheets scoped to the user's location
function wiw_render_client_portal() {
    // 1. Membership Gate
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view timesheets.</p>';
    }

    // 2. Fetch Data (Scoping happens automatically inside the function above)
    $timesheets = wiw_get_timesheets();

    if (empty($timesheets)) {
        return '<p>No timesheets found for your location.</p>';
    }

    // 3. Simple Table Output (Traditional Refresh Style)
    ob_start();
    ?>
    <div class="wiw-portal-container">
        <h2>Your Location Timesheets</h2>
        <table class="wiw-portal-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Hours</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($timesheets as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->date); ?></td>
                        <td><?php echo esc_html($row->employee_name); ?></td>
                        <td><?php echo esc_html($row->hours); ?></td>
                        <td><?php echo esc_html($row->status); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}