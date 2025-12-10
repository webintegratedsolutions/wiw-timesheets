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
            $error_message = $timesheets_data->get_error_message();
            // ... (Error handling remains the same)
            ?>
            <div class="notice notice-error">
                <p><strong>❌ Timesheet Fetch Error:</strong> <?php echo esc_html($error_message); ?></p>
                <?php if ($timesheets_data->get_error_code() === 'wiw_token_missing') : ?>
                    <p>Please go to the <a href="<?php echo esc_url(admin_url('admin.php?page=wiw-timesheets-settings')); ?>">Settings Page</a> to log in and save your session token.</p>
                <?php endif; ?>
            </div>
            <?php
        } else {
            // Extract data arrays
            $times = isset($timesheets_data->times) ? $timesheets_data->times : [];
            $included_users = isset($timesheets_data->users) ? $timesheets_data->users : [];
            $included_positions = isset($timesheets_data->positions) ? $timesheets_data->positions : [];
            
            // Create Maps for easy lookup: ID => Object
            $user_map = array_column($included_users, null, 'id');
            $position_map = array_column($included_positions, null, 'id');
            
            // --- NEW: Apply Sorting ---
            $times = $this->sort_timesheet_data( $times, $user_map );
            // --- END NEW: Apply Sorting ---
            
            // --- Timezone Setup ---
            $wp_timezone_string = get_option('timezone_string');
            if (empty($wp_timezone_string)) {
                $wp_timezone_string = 'UTC';
            }
            $wp_timezone = new DateTimeZone($wp_timezone_string); 
            $time_format = get_option('time_format') ?: 'g:i A'; 
            // --- End Timezone Setup ---
            
            ?>
            <div class="notice notice-success"><p>✅ Timesheet data fetched successfully! (Includes <?php echo count($included_positions); ?> Positions)</p></div>
            
            <h2>Latest Timesheets (Last 30 Days)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="5%">Record ID</th>
                        <th width="10%">Date</th>
                        <th width="10%">Employee Name</th>
                        <th width="10%">Position</th>
                        <th width="10%">Clock In</th>
                        <th width="10%">Clock Out</th>
                        <th width="8%">Hrs</th>
                        <th width="8%">Status</th>
                        <th width="8%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if (empty($times)) : ?>
                        <tr><td colspan="9">No timesheet records found.</td></tr>
                    <?php else : 
                        foreach (array_slice($times, 0, 20) as $index => $time_entry) : 
                            
                            $time_id = $time_entry->id ?? 'N/A';
                            $user_id = $time_entry->user_id ?? 0;
                            $position_id = $time_entry->position_id ?? 0;
                            
                            $user = $user_map[$user_id] ?? null;
                            $position_obj = $position_map[$position_id] ?? null;

                            $employee_name = ($user && isset($user->first_name)) ? esc_html($user->first_name . ' ' . $user->last_name) : 'Unknown User';
                            $position_name = ($position_obj && isset($position_obj->name)) ? esc_html($position_obj->name) : 'N/A';
                            
                            $start_time_utc = $time_entry->start_time ?? ''; 
                            $end_time_utc = $time_entry->end_time ?? '';

                            // --- Date and Time Processing ---
                            $display_date = 'N/A';
                            $display_start_time = 'N/A';
                            $display_end_time = 'Active (N/A)';
                            $date_match = true;

                            try {
                                if (!empty($start_time_utc)) {
                                    $dt_start_utc = new DateTime($start_time_utc, new DateTimeZone('UTC'));
                                    $dt_start_utc->setTimezone($wp_timezone);
                                    
                                    $display_date = $dt_start_utc->format('Y-m-d');
                                    $display_start_time = $dt_start_utc->format($time_format);
                                }

                                if (!empty($end_time_utc)) {
                                    $dt_end_utc = new DateTime($end_time_utc, new DateTimeZone('UTC'));
                                    $dt_end_utc->setTimezone($wp_timezone);
                                    
                                    $display_end_time = $dt_end_utc->format($time_format);
                                    
                                    if ($display_date !== $dt_end_utc->format('Y-m-d')) {
                                        $date_match = false;
                                    }
                                }
                            } catch (Exception $e) {
                                $display_start_time = 'Error';
                                $display_end_time = 'Error';
                                $display_date = 'Error';
                            }
                            // --- End Date and Time Processing ---
                            
                            $duration = round(($time_entry->length ?? 0), 2);
                            if ($duration == 0 && isset($time_entry->duration)) {
                                 $duration = round(($time_entry->duration / 3600), 2);
                            }
                            
                            $status = (isset($time_entry->approved) && $time_entry->approved) ? 'Approved' : 'Pending';

                            $row_id = 'wiw-raw-' . $index;
                            
                            $date_cell_style = ($date_match || $display_end_time === 'Active (N/A)') ? '' : 'style="background-color: #ffe0e0;" title="Clock out date does not match clock in date."';
                        ?>
                            <tr>
                                <td><?php echo esc_html($time_id); ?></td>
                                <td <?php echo $date_cell_style; ?>><?php echo esc_html($display_date); ?></td>
                                <td><?php echo $employee_name; ?></td>
                                <td><?php echo $position_name; ?></td>
                                <td><?php echo esc_html($display_start_time); ?></td>
                                <td><?php echo esc_html($display_end_time); ?></td>
                                <td><?php echo esc_html($duration); ?></td>
                                <td><?php echo esc_html($status); ?></td>
                                <td>
                                    <button type="button" class="button action-toggle-raw" data-target="<?php echo esc_attr($row_id); ?>">
                                        View Data
                                    </button>
                                </td>
                            </tr>
                            
                            <tr id="<?php echo esc_attr($row_id); ?>" style="display:none; background-color: #f9f9f9;">
                                <td colspan="9">
                                    <div style="padding: 10px; border: 1px solid #ccc; max-height: 300px; overflow: auto;">
                                        <strong>Raw API Data:</strong>
                                        <pre style="font-size: 11px;"><?php print_r($time_entry); ?></pre>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach;
                    endif;
                    ?>
                </tbody>
            </table>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('.action-toggle-raw').on('click', function() {
                        var targetId = $(this).data('target');
                        $('#' + targetId).toggle();
                    });
                });
            </script>

            <hr/>

            <h2>Data Reference Legend</h2>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">Record ID</th>
                        <td>The unique identifier assigned to this clock-in/clock-out entry.</td>
                    </tr>
                    <tr>
                        <th scope="row">Date</th>
                        <td>The date of the Clock In time, adjusted to the local timezone. This field is highlighted if the Clock Out date is different (i.e., an overnight shift).</td>
                    </tr>
                    <tr>
                        <th scope="row">Employee Name</th>
                        <td>The employee's full name.</td>
                    </tr>
                    <tr>
                        <th scope="row">Position</th>
                        <td>The job or role associated with the shift (e.g., ECA, RECE).</td>
                    </tr>
                    <tr>
                        <th scope="row">Clock In</th>
                        <td>The **time** the employee started the shift, converted to the local timezone and displayed in 12-hour format (e.g., 8:14 am).</td>
                    </tr>
                    <tr>
                        <th scope="row">Clock Out</th>
                        <td>The **time** the employee ended the shift, converted to the local timezone and displayed in 12-hour format. Displays **"Active (N/A)"** if the shift is currently open.</td>
                    </tr>
                    <tr>
                        <th scope="row">Hrs</th>
                        <td>The calculated duration of the shift in hours (e.g., 8.00 hours).</td>
                    </tr>
                    <tr>
                        <th scope="row">Status</th>
                        <td>The timesheet approval status (**Pending** or **Approved**).</td>
                    </tr>
                    <tr>
                        <th scope="row">Actions</th>
                        <td>The interactive column (View Data button).</td>
                    </tr>
                </tbody>
            </table>
            <?php
        }
        ?>
    </div>
    <?php
}

/**
 * Sorts timesheet data first by Employee Name (A-Z) and then by Clock In Time (Oldest to Newest).
 *
 * @param array $times The raw times array.
 * @param array $user_map Map of user IDs to user objects.
 * @return array The sorted times array.
 */
private function sort_timesheet_data( $times, $user_map ) {
    usort( $times, function( $a, $b ) use ( $user_map ) {
        $a_user_id = $a->user_id ?? 0;
        $b_user_id = $b->user_id ?? 0;

        $a_user = $user_map[$a_user_id] ?? (object)['first_name' => 'ZZZ', 'last_name' => 'Unknown'];
        $b_user = $user_map[$b_user_id] ?? (object)['first_name' => 'ZZZ', 'last_name' => 'Unknown'];

        $a_name = ($a_user->first_name ?? '') . ($a_user->last_name ?? '');
        $b_name = ($b_user->first_name ?? '') . ($b_user->last_name ?? '');

        // 1. Sort by Employee Name (A-Z)
        $name_comparison = strcasecmp( $a_name, $b_name );
        
        if ( $name_comparison !== 0 ) {
            return $name_comparison;
        }

        // 2. If names are the same, sort by Clock In Time (Oldest to Newest)
        $a_time = strtotime( $a->start_time ?? '' );
        $b_time = strtotime( $b->start_time ?? '' );

        if ( $a_time === $b_time ) {
            return 0;
        }

        return ($a_time < $b_time) ? -1 : 1;
    });

    return $times;
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

/**
 * Fetches timesheet data from the When I Work API.
 * * @param array $filters Optional filters (e.g., date ranges).
 * @return object|WP_Error The API response object or a WP_Error object.
 */
private function fetch_timesheets_data($filters = []) {
    $endpoint = 'times'; 
    
    $default_filters = [
        // UPDATE: Add 'positions' to the include list
        'include' => 'users,shifts,positions', 
        
        // This keeps the start date for the last 30 days
        'start' => date('Y-m-d', strtotime('-30 days')),
        
        // **UPDATE:** Set end date to TOMORROW to ensure today's records are included.
        'end'   => date('Y-m-d', strtotime('+1 day')),
    ];

    $params = array_merge($default_filters, $filters);

    $result = Wheniwork::request($endpoint, $params, Wheniwork::METHOD_GET);

    return $result;
}

}

// Instantiate the core class
new WIW_Timesheet_Manager();
