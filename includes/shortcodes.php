<?php
/**
 * Shortcodes for WIW Timesheets Front-end Portal
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wiw_render_client_portal' ) ) {
    function wiw_render_client_portal() {
        if ( ! is_user_logged_in() ) return '<p>Please log in.</p>';

        $timesheets = wiw_get_timesheets();

        ob_start();
        ?>
        <div class="wiw-portal-wrap">
            <style>
                .wiw-btn { padding: 6px 12px; border-radius: 3px; border: none; cursor: pointer; }
                .wiw-btn-primary { background: #0073aa; color: #fff; }
                .wiw-btn-disabled { background: #ccc; color: #666; cursor: not-allowed; }
            </style>
            <table style="width:100%; text-align:left; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="padding:10px; border-bottom:2px solid #ccc;">Week Ending</th>
                        <th style="padding:10px; border-bottom:2px solid #ccc;">Employee</th>
                        <th style="padding:10px; border-bottom:2px solid #ccc;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $timesheets as $row ) : ?>
                        <tr>
                            <td style="padding:10px; border-bottom:1px solid #eee;"><?php echo esc_html( $row->date ); ?></td>
                            <td style="padding:10px; border-bottom:1px solid #eee;"><?php echo esc_html( $row->employee_name ); ?></td>
                            <td style="padding:10px; border-bottom:1px solid #eee;">
                                <?php if ( strtolower($row->status) === 'approved' ) : ?>
                                    <span style="color:green; font-weight:bold;">Signed Off</span>
                                <?php elseif ( $row->total_days > 0 && $row->unapproved_days == 0 ) : ?>
                                    <form method="POST">
                                        <?php wp_nonce_field( 'wiw_approve_' . $row->id, 'wiw_nonce' ); ?>
                                        <input type="hidden" name="timesheet_id" value="<?php echo $row->id; ?>">
                                        <input type="hidden" name="wiw_action" value="approve_timesheet">
                                        <button type="submit" class="wiw-btn wiw-btn-primary">Sign Off</button>
                                    </form>
                                <?php else : ?>
                                    <button class="wiw-btn wiw-btn-disabled" disabled>Sign Off</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
add_shortcode( 'wiw_client_portal', 'wiw_render_client_portal' );
