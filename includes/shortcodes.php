<?php
/**
 * Shortcodes for WIW Timesheets Front-end Portal
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wiw_render_client_portal' ) ) {
    function wiw_render_client_portal() {
        if ( ! is_user_logged_in() ) {
            return '<p>Please <a href="' . wp_login_url() . '">log in</a> to view your timesheets.</p>';
        }

        $timesheets = wiw_get_timesheets();

        ob_start();
        ?>
        <div class="wiw-portal-wrap">
            <style>
                .wiw-btn { padding: 8px 16px; border-radius: 4px; border: none; cursor: pointer; font-weight: 600; }
                .wiw-btn-primary { background: #0073aa; color: #fff; }
                .wiw-btn-disabled { background: #e0e0e0; color: #999; cursor: not-allowed; }
                .wiw-status-label { font-weight: bold; color: green; }
            </style>

            <h3>Location Timesheets</h3>
            <?php if ( empty( $timesheets ) ) : ?>
                <p>No records found for your assigned location.</p>
            <?php else : ?>
                <table style="width:100%; text-align:left; border-collapse: collapse; margin-top: 15px;">
                    <thead>
                        <tr>
                            <th style="border-bottom: 2px solid #ddd; padding: 12px;">Week Ending</th>
                            <th style="border-bottom: 2px solid #ddd; padding: 12px;">Employee</th>
                            <th style="border-bottom: 2px solid #ddd; padding: 12px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $timesheets as $row ) : ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid #eee;"><?php echo esc_html( $row->date ); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid #eee;"><?php echo esc_html( $row->employee_name ); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid #eee;">
                                    <?php 
                                    $status = strtolower( trim( $row->status ) );
                                    
                                    if ( $status === 'approved' ) : ?>
                                        <span class="wiw-status-label">Signed Off</span>
                                    <?php elseif ( $status === 'processed' ) : ?>
                                        <form method="POST" style="display:inline;">
                                            <?php wp_nonce_field( 'wiw_approve_' . $row->id, 'wiw_nonce' ); ?>
                                            <input type="hidden" name="timesheet_id" value="<?php echo esc_attr( $row->id ); ?>">
                                            <input type="hidden" name="wiw_action" value="approve_timesheet">
                                            <button type="submit" class="wiw-btn wiw-btn-primary">Sign Off</button>
                                        </form>
                                    <?php else : ?>
                                        <button class="wiw-btn wiw-btn-disabled" disabled title="Pending daily record approval">Sign Off</button>
                                    <?php endif; ?>
                                </td>
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

add_shortcode( 'wiw_client_portal', 'wiw_render_client_portal' );
