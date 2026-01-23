<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin menu + settings for WIW Timesheets Manager.
 *
 * This file contains methods that belong to the WIW_Timesheet_Manager class.
 * It must be loaded before the class is instantiated.
 */

trait WIW_Timesheet_Admin_Settings_Trait {

    public function add_admin_menus() {
        add_menu_page(
            'WIW Timesheets',
            'WIW Timesheets',
            'manage_options',
            'wiw-timesheets',
            array( $this, 'admin_timesheets_page' ),
            'dashicons-clock',
            6
        );

        add_submenu_page(
            'wiw-timesheets',
            'WIW Shifts',
            'Shifts',
            'manage_options',
            'wiw-shifts',
            array( $this, 'admin_shifts_page' )
        );

        add_submenu_page(
            'wiw-timesheets',
            'WIW Employees',
            'Employees',
            'manage_options',
            'wiw-employees',
            array( $this, 'admin_employees_page' )
        );

        add_submenu_page(
            'wiw-timesheets',
            'WIW Locations',
            'Locations',
            'manage_options',
            'wiw-locations',
            array( $this, 'admin_locations_page' )
        );

        add_submenu_page(
            'wiw-timesheets',
            'Local Timesheets',
            'Local Timesheets',
            'manage_options',
            'wiw-local-timesheets',
            array( $this, 'admin_local_timesheets_page' )
        );

        add_submenu_page(
            'wiw-timesheets',
            'WIW Settings',
            'Settings',
            'manage_options',
            'wiw-timesheets-settings',
            array( $this, 'admin_settings_page' )
        );

        add_submenu_page(
            'wiw-timesheets',
            'Approvals',
            'Approvals',
            'manage_options',
            'wiw-timesheets-auto-approve-run',
            array( $this, 'admin_auto_approve_run_page' )
        );
    }

    public function register_settings() {
        register_setting( 'wiw_timesheets_group', 'wiw_api_key' );
        register_setting( 'wiw_timesheets_group', 'wiw_login_email' );
        register_setting( 'wiw_timesheets_group', 'wiw_login_password' );
        register_setting( 'wiw_timesheets_group', 'wiw_session_token' );
        register_setting( 'wiw_timesheets_group', 'wiw_enable_auto_approvals' );
        register_setting( 'wiw_timesheets_group', 'wiw_auto_approve_report_email' );

        add_settings_section(
            'wiw_api_credentials_section',
            'API Credentials',
            array( $this, 'settings_section_callback' ),
            'wiw-timesheets-settings'
        );

        add_settings_field(
            'wiw_api_key_field',
            'API Key',
            array( $this, 'text_input_callback' ),
            'wiw-timesheets-settings',
            'wiw_api_credentials_section',
            array( 'setting_key' => 'wiw_api_key' )
        );

        add_settings_field(
            'wiw_login_email_field',
            'Email Address',
            array( $this, 'text_input_callback' ),
            'wiw-timesheets-settings',
            'wiw_api_credentials_section',
            array( 'setting_key' => 'wiw_login_email' )
        );

        add_settings_field(
            'wiw_login_password_field',
            'Password',
            array( $this, 'password_input_callback' ),
            'wiw-timesheets-settings',
            'wiw_api_credentials_section',
            array( 'setting_key' => 'wiw_login_password' )
        );

        add_settings_field(
            'wiw_enable_auto_approvals_field',
            'Enable Auto Approvals',
            array( $this, 'checkbox_input_callback' ),
            'wiw-timesheets-settings',
            'wiw_api_credentials_section',
            array(
                'setting_key' => 'wiw_enable_auto_approvals',
                'label'       => 'Enable auto-approvals for past-due timesheets (dry-run only for now).',
            )
        );

        add_settings_field(
            'wiw_auto_approve_report_email_field',
            'Auto-Approval Report Email',
            array( $this, 'email_input_callback' ),
            'wiw-timesheets-settings',
            'wiw_api_credentials_section',
            array(
                'setting_key' => 'wiw_auto_approve_report_email',
                'description' => 'Where to send auto-approval reports once email delivery is enabled.',
            )
        );
    }

    public function text_input_callback( $args ) {
        $key   = $args['setting_key'];
        $value = get_option( $key );
        echo '<input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="width: 500px;" />';
    }

    public function password_input_callback( $args ) {
        $key   = $args['setting_key'];
        $value = get_option( $key );
        echo '<input type="password" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="width: 500px;" placeholder="Leave blank to keep current password" />';
        echo '<p class="description">The password is required to obtain a new session token.</p>';
    }

    public function email_input_callback( $args ) {
        $key         = $args['setting_key'];
        $value       = get_option( $key );
        $description = isset( $args['description'] ) ? (string) $args['description'] : '';
        echo '<input type="email" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="width: 500px;" />';
        if ( $description !== '' ) {
            echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
    }

    public function checkbox_input_callback( $args ) {
        $key   = $args['setting_key'];
        $label = isset( $args['label'] ) ? (string) $args['label'] : '';
        $value = get_option( $key );

        echo '<label for="' . esc_attr( $key ) . '">';
        echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( '1', (string) $value, false ) . ' />';
        if ( $label !== '' ) {
            echo ' ' . esc_html( $label );
        }
        echo '</label>';
    }

    public function settings_section_callback() {
        echo '<p>Enter your When I Work API credentials below.</p>';
    }

    public function admin_settings_page() {
        ?>
        <div class="wrap">
            <h1>When I Work Timesheets Settings</h1>

            <?php
            $session_token       = get_option( 'wiw_session_token' );
            $show_login_messages = true;

            if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Credentials Saved Successfully!</strong></p></div>';
                $show_login_messages = false;
            }

            if ( $show_login_messages ) {
                if ( isset( $_GET['wiw_login_success'] ) ) {
                    echo '<div class="notice notice-success is-dismissible"><p><strong>✅ API Login Successful!</strong> Session Token saved.</p></div>';
                }
                if ( isset( $_GET['wiw_login_error'] ) && ! empty( $_GET['wiw_login_error'] ) ) {
                    $error_message = sanitize_text_field( urldecode( $_GET['wiw_login_error'] ) );
                    echo '<div class="notice notice-error is-dismissible"><p><strong>❌ API Login Failed:</strong> ' . esc_html( $error_message ) . '</p></div>';
                }
            }

            if ( $session_token ) {
                echo '<div class="notice notice-info"><p><strong>Current Session Token:</strong> A valid token is stored and will be used for API requests.</p></div>';
            }

            ?>

            <form method="post" action="options.php">
                <h2>1. Save API Credentials</h2>
                <?php
                settings_fields( 'wiw_timesheets_group' );
                do_settings_sections( 'wiw-timesheets-settings' );
                submit_button( 'Save Credentials', 'primary', 'submit_settings' );
                ?>
            </form>

            <hr/>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <h2>2. Get Session Token</h2>
                <p>Once credentials are saved above, click the button below to log into the When I Work API and retrieve the required session token.</p>

                <input type="hidden" name="action" value="wiw_login_handler" />
                <?php wp_nonce_field( 'wiw_login_action', 'wiw_login_nonce' ); ?>

                <?php submit_button( 'Log In & Get Session Token', 'secondary', 'submit_login' ); ?>
            </form>
        </div>
        <?php
    }

    public function admin_auto_approve_run_page() {
        $ran_status = isset( $_GET['wiwts_auto_approve_cron_ran'] )
            ? sanitize_text_field( wp_unslash( $_GET['wiwts_auto_approve_cron_ran'] ) )
            : '';
        ?>
        <div class="wrap">
            <h1>Approvals</h1>
            <?php if ( $ran_status === '1' ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Auto-approve cron triggered.</strong> Check the report email/logs for details.</p>
                </div>
            <?php elseif ( $ran_status === 'disabled' ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>❌ Auto-approve run blocked:</strong> Enable auto-approvals in Settings first.</p>
                </div>
            <?php endif; ?>
            <?php if ( isset( $_GET['wiwts_report_generated'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Auto-approval report generated.</strong></p>
                </div>
            <?php endif; ?>
            <?php if ( isset( $_GET['wiwts_report_emailed'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Auto-approval report emailed successfully.</strong></p>
                </div>
            <?php endif; ?>
            <?php if ( isset( $_GET['wiwts_report_log_purged'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Auto-approval report log purged.</strong></p>
                </div>
            <?php endif; ?>
            <?php if ( isset( $_GET['wiwts_report_email_error'] ) ) : ?>
                <?php
                $error_code = sanitize_text_field( wp_unslash( $_GET['wiwts_report_email_error'] ) );
                if ( $error_code === 'missing_email' ) {
                    $error_message = 'Please set a valid report email address before sending.';
                } elseif ( $error_code === 'missing_report' ) {
                    $error_message = 'Please generate an auto-approval report before sending.';
                } else {
                    $error_message = 'Report email could not be sent. Please check your mail configuration.';
                }
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>❌ Auto-approval report email failed:</strong> <?php echo esc_html( $error_message ); ?></p>
                </div>
            <?php endif; ?>
            <?php if ( isset( $_GET['wiwts_auto_approve_run'] ) ) : ?>
                <?php
                $run_status = sanitize_text_field( wp_unslash( $_GET['wiwts_auto_approve_run'] ) );
                if ( $run_status === 'disabled' ) {
                    $run_message = 'Enable auto-approvals before running.';
                    $run_notice  = 'notice-error';
                    $run_prefix  = '❌ Auto-approval run blocked:';
                } else {
                    $approved   = isset( $_GET['approved'] ) ? absint( $_GET['approved'] ) : 0;
                    $skipped    = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
                    $updated    = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0;
                    $run_message = 'Approved: ' . (string) $approved . ', Updated: ' . (string) $updated . ', Skipped: ' . (string) $skipped . '.';
                    $run_notice  = 'notice-success';
                    $run_prefix  = '✅ Auto-approval run completed.';
                }
                ?>
                <div class="notice <?php echo esc_attr( $run_notice ); ?> is-dismissible">
                    <p><strong><?php echo esc_html( $run_prefix ); ?></strong> <?php echo esc_html( $run_message ); ?></p>
                </div>
            <?php endif; ?>

            <h2>1. Run Auto-Approve Now</h2>
            <p>This page triggers the auto-approve cron hook immediately (same flow as the scheduled run).</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wiwts_run_auto_approve_cron_now" />
                <?php wp_nonce_field( 'wiwts_run_auto_approve_cron_now', 'wiwts_run_auto_approve_cron_now_nonce' ); ?>
                <?php submit_button( 'Run Auto-Approve Now', 'primary' ); ?>
            </form>

            <hr/>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <h2>2. Generate Auto-Approval Dry-Run Report</h2>
                <p>Generate a read-only dry-run report that mirrors the current preview page and email it to the configured report email address.</p>

                <input type="hidden" name="action" value="wiwts_generate_auto_approve_report" />
                <?php wp_nonce_field( 'wiwts_generate_auto_approve_report', 'wiwts_generate_auto_approve_report_nonce' ); ?>

                <?php submit_button( 'Generate & Email Dry-Run Report', 'secondary', 'submit_dry_run_report' ); ?>
            </form>

            <hr/>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <h2>3. Email Latest Auto-Approval Report</h2>
                <p>Send the most recent auto-approval report to the configured report email address.</p>

                <input type="hidden" name="action" value="wiwts_send_auto_approve_report_email" />
                <?php wp_nonce_field( 'wiwts_send_auto_approve_report_email', 'wiwts_send_auto_approve_report_email_nonce' ); ?>

                <?php
                $report_email = get_option( 'wiw_auto_approve_report_email' );
                $report_entry = get_option( 'wiwts_auto_approve_dry_run_report', array() );
                $email_ready  = is_string( $report_email ) && is_email( $report_email );
                $report_ready = is_array( $report_entry ) && ! empty( $report_entry );
                if ( ! $email_ready ) {
                    echo '<p class="description">Set a valid report email above before sending.</p>';
                }
                if ( ! $report_ready ) {
                    echo '<p class="description">Generate an auto-approval report first to enable sending.</p>';
                }
                ?>

                <?php
                $send_disabled = ! ( $email_ready && $report_ready );
                $send_attrs    = $send_disabled ? ' disabled="disabled" aria-disabled="true"' : '';
                ?>
                <p>
                    <button type="submit" class="button button-secondary" name="submit_send_report_email"<?php echo $send_attrs; ?>>
                        Send Latest Report Email
                    </button>
                </p>
            </form>

            <hr/>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <h2>4. Manual Run Auto-Approvals</h2>
                <p>Run Step 5 auto-approvals once (includes Flag 104/106 auto-fixes). Requires auto-approvals to be enabled.</p>

                <input type="hidden" name="action" value="wiwts_manual_run_auto_approve" />
                <?php wp_nonce_field( 'wiwts_manual_run_auto_approve', 'wiwts_manual_run_auto_approve_nonce' ); ?>

                <?php
                $auto_approve_enabled = (string) get_option( 'wiw_enable_auto_approvals', '' ) === '1';
                if ( ! $auto_approve_enabled ) {
                    echo '<p class="description">Enable auto-approvals above to run this action.</p>';
                }
                $manual_run_disabled = ! $auto_approve_enabled;
                $manual_run_attrs    = $manual_run_disabled ? ' disabled="disabled" aria-disabled="true"' : '';
                ?>
                <p>
                    <button type="submit" class="button button-secondary" name="submit_manual_auto_approve"<?php echo $manual_run_attrs; ?>>
                        Manual Run Auto-Approvals
                    </button>
                </p>
            </form>

            <hr/>

            <h2>5. Auto-Approval Report Logs</h2>
            <p>This log stores every generated auto-approval report for audit purposes.</p>
            <!-- === WIWTS PURGE REPORT LOG FORM BEGIN === -->
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wiwts_purge_auto_approve_report_log" />
                <?php wp_nonce_field( 'wiwts_purge_auto_approve_report_log', 'wiwts_purge_auto_approve_report_log_nonce' ); ?>
                <p>
                    <button type="submit" class="button button-secondary" name="submit_purge_auto_approve_report_log" onclick="return confirm('Permanently delete all auto-approval report log entries?');">
                        Purge Report Log
                    </button>
                </p>
            </form>
            <!-- === WIWTS PURGE REPORT LOG FORM END === -->
            <?php
            $report_log = get_option( 'wiwts_auto_approve_dry_run_report_log', array() );
            if ( ! is_array( $report_log ) ) {
                $report_log = array();
            }
            ?>
            <?php if ( empty( $report_log ) ) : ?>
                <p><em>No reports have been generated yet.</em></p>
            <?php else : ?>
                <div style="max-width:1000px;">
                    <?php foreach ( array_reverse( $report_log ) as $index => $report_entry ) : ?>
                        <?php
                        $generated_at = isset( $report_entry['generated_at'] ) ? $report_entry['generated_at'] : '';
                        $report_text  = isset( $report_entry['report_text'] ) ? $report_entry['report_text'] : '';
                        $table_html   = isset( $report_entry['table_html'] ) ? $report_entry['table_html'] : '';
                        ?>
                        <details style="margin:0 0 12px; padding:12px; border:1px solid #ccd0d4; border-radius:4px; background:#fff;">
                            <summary style="cursor:pointer; font-weight:600;">
                                <?php echo esc_html( $generated_at !== '' ? $generated_at : 'Report #' . ( $index + 1 ) ); ?>
                            </summary>
                            <?php if ( $report_text !== '' ) : ?>
                                <pre style="white-space:pre-wrap; margin-top:12px;"><?php echo esc_html( $report_text ); ?></pre>
                            <?php endif; ?>
                            <?php if ( $table_html !== '' ) : ?>
                                <div style="margin-top:12px;"><?php echo wp_kses_post( $table_html ); ?></div>
                            <?php endif; ?>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function wiwts_handle_run_auto_approve_cron_now(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }

        if (
            ! isset( $_POST['wiwts_run_auto_approve_cron_now_nonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['wiwts_run_auto_approve_cron_now_nonce'] ) ),
                'wiwts_run_auto_approve_cron_now'
            )
        ) {
            wp_die( 'Security check failed.' );
        }

        $redirect_url = admin_url( 'admin.php?page=wiw-timesheets-auto-approve-run' );
        $auto_approve_enabled = (string) get_option( 'wiw_enable_auto_approvals', '' ) === '1';

        if ( ! $auto_approve_enabled ) {
            wp_safe_redirect( add_query_arg( 'wiwts_auto_approve_cron_ran', 'disabled', $redirect_url ) );
            exit;
        }

        do_action( 'wiwts_auto_approve_past_due_run' );

        wp_safe_redirect( add_query_arg( 'wiwts_auto_approve_cron_ran', '1', $redirect_url ) );
        exit;
    }
}
