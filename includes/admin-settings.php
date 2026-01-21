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
    }

    public function register_settings() {
        register_setting( 'wiw_timesheets_group', 'wiw_api_key' );
        register_setting( 'wiw_timesheets_group', 'wiw_login_email' );
        register_setting( 'wiw_timesheets_group', 'wiw_login_password' );
        register_setting( 'wiw_timesheets_group', 'wiw_session_token' );
        register_setting( 'wiw_timesheets_group', 'wiw_enable_auto_approvals' );

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
}
