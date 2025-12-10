<?php
/**
 * Plugin Name: When I Work Timesheets Manager
 * Description: Integrates with the When I Work API to manage and approve employee timesheets.
 * Version: 1.0.0
 * Author: Web Integrated Solutions
 * License: GPL2
 */

// Exit if accessed directly (security)
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin path for easy file reference
if ( ! defined( 'WIW_PLUGIN_PATH' ) ) {
    define( 'WIW_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

// 💥 IMPORTANT: Include the When I Work API Wrapper file
require_once WIW_PLUGIN_PATH . 'includes/wheniwork.php';

/**
 * Core Plugin Class
 */
class WIW_Timesheet_Manager {

    public function __construct() {
        // 1. Add Admin Menus and Settings
        add_action( 'admin_menu', array( $this, 'add_admin_menus' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // 2. Add Front-end UI (Client Area) via Shortcode
        add_shortcode( 'wiw_timesheets_client', array( $this, 'render_client_ui' ) );

        // 3. Handle AJAX requests for viewing/adjusting/approving (Crucial for interaction)
        add_action( 'wp_ajax_wiw_fetch_timesheets', array( $this, 'handle_fetch_timesheets' ) );
        add_action( 'wp_ajax_wiw_approve_timesheet', array( $this, 'handle_approve_timesheet' ) );
        // NOTE: The 'nopriv' hook is generally NOT used for secure client areas.

        // 4. Handle Login Request via POST (when admin clicks "Log In" button)
        add_action( 'admin_post_wiw_login_handler', array( $this, 'handle_wiw_login' ) );
    }

    /**
     * Set up the administrative menu pages.
     */
    public function add_admin_menus() {
        add_menu_page(
            'WIW Timesheets',        // Page Title
            'WIW Timesheets',        // Menu Title
            'manage_options',        // Capability required (Admin)
            'wiw-timesheets',        // Menu slug
            array( $this, 'admin_timesheets_page' ), // Callback function
            'dashicons-clock',       // Icon
            6                        // Position
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

/**
 * Renders the main Timesheets management page (Admin Area).
 */
public function admin_timesheets_page() {
    ?>
    <div class="wrap">
        <h1>🗓️ When I Work Timesheet Dashboard</h1>
        
        <?php 
        $timesheets_data = $this->fetch_timesheets_data();
        
        if ( is_wp_error( $timesheets_data ) ) {
            // Display Token Missing or API Error
            $error_message = $timesheets_data->get_error_message();
            ?>
            <div class="notice notice-error">
                <p><strong>❌ Timesheet Fetch Error:</strong> <?php echo esc_html($error_message); ?></p>
                <?php if ($timesheets_data->get_error_code() === 'wiw_token_missing') : ?>
                    <p>Please go to the <a href="<?php echo esc_url(admin_url('admin.php?page=wiw-timesheets-settings')); ?>">Settings Page</a> to log in and save your session token.</p>
                <?php endif; ?>
            </div>
            <?php
        } else {
            // Success! Display a table structure for the timesheets.
            ?>
            <div class="notice notice-success"><p>✅ Timesheet data fetched successfully!</p></div>
            
            <h2>Latest Timesheets</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="15%">User ID</th>
                        <th width="25%">Clock In</th>
                        <th width="25%">Clock Out</th>
                        <th width="15%">Duration</th>
                        <th width="20%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // When I Work returns times in the 'times' property of the response object
                    $times = isset($timesheets_data->times) ? $timesheets_data->times : [];

                    if (empty($times)) : ?>
                        <tr><td colspan="5">No timesheet records found in the API response.</td></tr>
                    <?php else : 
                        // Display the first 10 records as a sample
                        foreach (array_slice($times, 0, 10) as $time_entry) : 
                            // Example of accessing data (structure must match WIW API response)
                            $user_id = $time_entry->user_id ?? 'N/A';
                            $start_time = $time_entry->start_time ?? 'N/A';
                            $end_time = $time_entry->end_time ?? 'N/A';
                            $duration = $time_entry->duration ?? 'N/A';
                            
                            // Placeholder for status logic
                            $status = 'Pending';
                            if (isset($time_entry->approved) && $time_entry->approved) {
                                $status = 'Approved';
                            }

                            // Simple date formatting for display
                            $display_start = ($start_time !== 'N/A') ? date('Y-m-d H:i', strtotime($start_time)) : 'N/A';
                            $display_end   = ($end_time !== 'N/A') ? date('Y-m-d H:i', strtotime($end_time)) : 'N/A';
                        ?>
                            <tr>
                                <td><?php echo esc_html($user_id); ?></td>
                                <td><?php echo esc_html($display_start); ?></td>
                                <td><?php echo esc_html($display_end); ?></td>
                                <td><?php echo esc_html($duration); ?></td>
                                <td><?php echo esc_html($status); ?></td>
                            </tr>
                        <?php endforeach;
                    endif;
                    ?>
                </tbody>
            </table>
            <?php
        }
        ?>
    </div>
    <?php
}

/**
 * Register the settings fields and sections.
 */
public function register_settings() {
    // OLD: register_setting( 'wiw_timesheets_group', 'wiw_login_username' );
    register_setting( 'wiw_timesheets_group', 'wiw_api_key' ); // <-- NEW
    register_setting( 'wiw_timesheets_group', 'wiw_login_email' );
    register_setting( 'wiw_timesheets_group', 'wiw_login_password' );
    
    // The session token that we will store after a successful login
    register_setting( 'wiw_timesheets_group', 'wiw_session_token' ); 
    
    add_settings_section(
        'wiw_api_credentials_section', // ID
        'API Credentials',            // Title
        array( $this, 'settings_section_callback' ), // Callback
        'wiw-timesheets-settings'     // Page slug
    );
    
    // Update the settings field definition for the key
    // OLD: 'wiw_login_username_field'
    add_settings_field(
        'wiw_api_key_field', // <-- NEW
        // OLD: 'Username (or Employee Code)',
        'API Key', // <-- NEW Title
        array( $this, 'text_input_callback' ),
        'wiw-timesheets-settings',
        'wiw_api_credentials_section',
        // OLD: array('setting_key' => 'wiw_login_username')
        array('setting_key' => 'wiw_api_key') // <-- NEW Key
    );
    
    add_settings_field(
        'wiw_login_email_field',
        'Email Address',
        array( $this, 'text_input_callback' ),
        'wiw-timesheets-settings',
        'wiw_api_credentials_section',
        array('setting_key' => 'wiw_login_email')
    );

    add_settings_field(
        'wiw_login_password_field',
        'Password',
        array( $this, 'password_input_callback' ),
        'wiw-timesheets-settings',
        'wiw_api_credentials_section',
        array('setting_key' => 'wiw_login_password')
    );
}

/**
 * Handles the login request when the admin submits the login form.
 */
public function handle_wiw_login() {
    // 1. Basic Security Check (Nonce & Capability)
    if ( ! isset( $_POST['wiw_login_nonce'] ) || ! wp_verify_nonce( $_POST['wiw_login_nonce'], 'wiw_login_action' ) || ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Security check failed.' );
    }

    // 2. Get Credentials from saved options
    $api_key = get_option( 'wiw_api_key' );      // <-- Correctly retrieving the API Key
    $email   = get_option( 'wiw_login_email' );
    $password = get_option( 'wiw_login_password' );

    // 3. Perform the Login API Call, passing API Key as first argument (as required by the new function)
    $login_result = Wheniwork::login( $api_key, $email, $password ); // <-- ARGUMENTS UPDATED

    $redirect_url = admin_url( 'admin.php?page=wiw-timesheets-settings' ); 

    if ( is_wp_error( $login_result ) ) {
        // Login Failed: Redirect with an error message
        $error_message = urlencode( $login_result->get_error_message() );
        wp_safe_redirect( add_query_arg( 'wiw_login_error', $error_message, $redirect_url ) );
        exit;
    }

    // 4. Login Success: Store the session token
    if ( !isset($login_result->login->token) ) {
        $error_message = urlencode( 'Login succeeded but token was not returned. Check API response structure.' );
        wp_safe_redirect( add_query_arg( 'wiw_login_error', $error_message, $redirect_url ) );
        exit;
    }
    
    $session_token = $login_result->login->token;
    update_option( 'wiw_session_token', $session_token );

    // Redirect with a success message
    wp_safe_redirect( add_query_arg( 'wiw_login_success', '1', $redirect_url ) );
    exit;
}

/**
 * Renders a generic text input field.
 * @param array $args Contains 'setting_key'.
 */
public function text_input_callback( $args ) {
    $key = $args['setting_key'];
    $value = get_option( $key );
    echo '<input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="width: 500px;" />';
}

/**
 * Renders a password input field.
 * @param array $args Contains 'setting_key'.
 */
public function password_input_callback( $args ) {
    $key = $args['setting_key'];
    // NOTE: We generally do NOT display the password after it's saved for security.
    $value = get_option( $key );
    echo '<input type="password" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="width: 500px;" placeholder="Leave blank to keep current password" />';
    echo '<p class="description">The password is required to obtain a new session token.</p>';
}

/**
 * Simple settings section callback.
 */
public function settings_section_callback() {
    echo '<p>Enter your When I Work API credentials below.</p>';
}

// Inside WIW_Timesheet_Manager class...

/**
 * Renders the main settings page HTML.
 */
public function admin_settings_page() {
    ?>
    <div class="wrap">
        <h1>When I Work Timesheets Settings</h1>

        <?php
        $session_token = get_option( 'wiw_session_token' );
        $show_login_messages = true; // Assume we show login messages unless a setting save overrides it

        // 1. Display standard WordPress settings saved message
        if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
            // When settings-updated is present, we know Form 1 was submitted successfully.
            // We show the generic success and suppress the login-related errors/successes.
            echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Credentials Saved Successfully!</strong></p></div>';
            $show_login_messages = false; 
        }
        
        // 2. Display Login Status Messages (only if Form 1 wasn't just submitted)
        if ( $show_login_messages ) {
            if ( isset( $_GET['wiw_login_success'] ) ) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>✅ API Login Successful!</strong> Session Token saved.</p></div>';
            }
            if ( isset( $_GET['wiw_login_error'] ) && ! empty( $_GET['wiw_login_error'] ) ) {
                $error_message = sanitize_text_field( urldecode( $_GET['wiw_login_error'] ) );
                echo '<div class="notice notice-error is-dismissible"><p><strong>❌ API Login Failed:</strong> ' . esc_html( $error_message ) . '</p></div>';
            }
        }
        
        // Display Token Status
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
            <p>Once credentials are saved above, click the button below to log into the When I Work API and retrieve the required session token. Note: If credentials are incorrect, the token retrieval will fail.</p>
            
            <input type="hidden" name="action" value="wiw_login_handler" />
            <?php wp_nonce_field( 'wiw_login_action', 'wiw_login_nonce' ); ?>
            
            <?php submit_button( 'Log In & Get Session Token', 'secondary', 'submit_login' ); ?>
        </form>
    </div>
    <?php
}

// Inside WIW_Timesheet_Manager class...

/**
 * Fetches timesheet data from the When I Work API.
 * * @param array $filters Optional filters (e.g., date ranges).
 * @return object|WP_Error The API response object or a WP_Error object.
 */
private function fetch_timesheets_data($filters = []) {
    // The endpoint for timesheet data is typically '/times' or similar in v2 of the API.
    $endpoint = 'times'; 
    
    // We can add default filters here (e.g., date range for the last week)
    $default_filters = [
        // Example: Filter for times that haven't been approved yet
        // 'approved' => 0, 
        
        // Example: Timesheet period (You'll need to confirm the exact date format for WIW)
        // 'start' => date('Y-m-d', strtotime('-7 days')),
        // 'end'   => date('Y-m-d'),
    ];

    $params = array_merge($default_filters, $filters);

    $result = Wheniwork::request($endpoint, $params, Wheniwork::METHOD_GET);

    return $result;
}

}

// Instantiate the core class
new WIW_Timesheet_Manager();
