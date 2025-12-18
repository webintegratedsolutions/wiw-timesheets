<?php
/**
 * Shortcodes for WIW Timesheets Front-end Portal
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders the Client Portal via [wiw_client_portal]
 */
if ( ! function_exists( 'wiw_render_client_portal' ) ) {
    function wiw_render_client_portal() {
        // 1. Security Check: Only logged-in users
        if ( ! is_user_logged_in() ) {
            return '<p>Please <a href="' . wp_login_url() . '">log in</a> to view your timesheets.</p>';
        }

        // 2. Fetch Scoped Data using our central function from Step 1
        $timesheets = wiw_get_timesheets();

        // 3. Simple Output Table
        ob_start();
        ?>
        <div class="wiw-portal-wrap">
            <h3>Location Timesheets</h3>
            <?php if ( empty( $timesheets ) ) : ?>
                <p>No records found for your assigned location.</p>
            <?php else : ?>
                <table class="wiw-portal-table" style="width:100%; text-align:left; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="border-bottom: 2px solid #ccc;">Date</th>
                            <th style="border-bottom: 2px solid #ccc;">Employee</th>
                            <th style="border-bottom: 2px solid #ccc;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $timesheets as $row ) : ?>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo esc_html( $row->date ); ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo esc_html( $row->employee_name ); ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo esc_html( $row->status ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Register the shortcode
add_shortcode( 'wiw_client_portal', 'wiw_render_client_portal' );
