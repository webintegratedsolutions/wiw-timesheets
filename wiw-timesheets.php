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

    // 2. Add Front-end UI (Client Area) via Shortcode (Still registered, but logic is deferred)
    add_shortcode( 'wiw_timesheets_client', array( $this, 'render_client_ui' ) );

    // 3. Handle AJAX requests for viewing/adjusting/approving (Crucial for interaction)
    // REMOVED: add_action( 'wp_ajax_wiw_fetch_timesheets', array( $this, 'handle_fetch_timesheets' ) );
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

        // Submenu page for Shifts
        add_submenu_page(
            'wiw-timesheets',
            'WIW Shifts',
            'Shifts',
            'manage_options',
            'wiw-shifts', // Unique slug for this page
            array( $this, 'admin_shifts_page' ) // Function that renders this page
        );

        // Submenu page for Employees
        add_submenu_page(
            'wiw-timesheets',
            'WIW Employees',
            'Employees',
            'manage_options',
            'wiw-employees', // Unique slug for this page
            array( $this, 'admin_employees_page' ) // Function that renders this page
        );

        // Submenu page for Locations
        add_submenu_page(
        'wiw-timesheets',
        'WIW Locations',
        'Locations',
        'manage_options',
        'wiw-locations', // Unique slug for this page
        array( $this, 'admin_locations_page' ) // Function that renders this page
        );

        // Settings Submenu
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
            ?>
            <div class="notice notice-error">
                <p><strong>❌ Timesheet Fetch Error:</strong> <?php echo esc_html($error_message); ?></p>
                <?php if ($timesheets_data->get_error_code() === 'wiw_token_missing') : ?>
                    <p>Please go to the <a href="<?php echo esc_url(admin_url('admin.php?page=wiw-timesheets-settings')); ?>">Settings Page</a> to log in and save your session token.</p>
                <?php endif; ?>
            </div>
            <?php
        } else {
            // Extract and map data
            $times = isset($timesheets_data->times) ? $timesheets_data->times : [];
            // --- FIX APPLIED HERE ---
            $included_users = isset($timesheets_data->users) ? $timesheets_data->users : [];
            $included_shifts = isset($timesheets_data->shifts) ? $timesheets_data->shifts : [];
            
            $user_map = array_column($included_users, null, 'id');
            $shift_map = array_column($included_shifts, null, 'id');
            
            // --- Timezone Setup ---
            $wp_timezone_string = get_option('timezone_string');
            if (empty($wp_timezone_string)) { $wp_timezone_string = 'UTC'; }
            $wp_timezone = new DateTimeZone($wp_timezone_string); 
            $time_format = get_option('time_format') ?: 'g:i A'; 
            // --- End Timezone Setup ---


            // --- PREPROCESSING STEP: Calculate durations and scheduled times ---
            if ( method_exists($this, 'calculate_shift_duration_in_hours') ) {
                foreach ($times as &$time_entry) {
                    // 1. Calculate Clocked Duration (for aggregation/display)
                    $clocked_duration = $this->calculate_timesheet_duration_in_hours($time_entry);
                    $time_entry->calculated_duration = $clocked_duration; 
                    
                    // 2. Map Scheduled Shift Data
                    $shift_id = $time_entry->shift_id ?? null;
                    $scheduled_shift_obj = $shift_map[$shift_id] ?? null;

                    // 3. Calculate Scheduled Duration and set display times
                    if ($scheduled_shift_obj) {
                        $time_entry->scheduled_duration = $this->calculate_shift_duration_in_hours($scheduled_shift_obj);
                        
                        $dt_sched_start = new DateTime($scheduled_shift_obj->start_time, new DateTimeZone('UTC'));
                        $dt_sched_end = new DateTime($scheduled_shift_obj->end_time, new DateTimeZone('UTC'));
                        $dt_sched_start->setTimezone($wp_timezone);
                        $dt_sched_end->setTimezone($wp_timezone);
                        
                        $time_entry->scheduled_shift_display = 
                            $dt_sched_start->format($time_format) . ' - ' . $dt_sched_end->format($time_format);

                    } else {
                        $time_entry->scheduled_duration = 0.0;
                        $time_entry->scheduled_shift_display = 'N/A';
                    }
                }
                unset($time_entry); 
            }
            // --- END PREPROCESSING ---


            // 1. Sort the records
            $times = $this->sort_timesheet_data( $times, $user_map );

            // 2. Group the records by employee and week (pay period)
            $grouped_timesheets = $this->group_timesheet_by_pay_period( $times, $user_map );
            
            ?>
            <div class="notice notice-success"><p>✅ Timesheet data fetched successfully!</p></div>
            
            <h2>Latest Timesheets (Grouped by Employee and Pay Period)</h2>

            <?php if (empty($grouped_timesheets)) : ?>
                <p>No timesheet records found within the filtered period.</p>
            <?php else : ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="5%">Record ID</th>
                        <th width="10%">Date</th>
                        <th width="15%">Employee Name</th>
                        <th width="15%">Scheduled Shift</th>
                        <th width="10%">Clock In</th>
                        <th width="10%">Clock Out</th>
                        <th width="7%">Hrs Clocked</th>
                        <th width="7%">Hrs Scheduled</th>
                        <th width="8%">Status</th>
                        <th width="7%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $global_row_index = 0;
                    foreach ($grouped_timesheets as $employee_name => $periods) : 
                        
                        // --- EMPLOYEE HEADER ROW ---
                        ?>
                        <tr class="wiw-employee-header">
                            <td colspan="10" style="background-color: #e6e6fa; font-weight: bold; font-size: 1.1em;">
                                👤 Employee: <?php echo esc_html($employee_name); ?>
                            </td>
                        </tr>
                        <?php

                        foreach ($periods as $period_start_date => $period_data) : 
                            $period_end_date = date('Y-m-d', strtotime($period_start_date . ' + 4 days'));
                            
                            $total_clocked = number_format($period_data['total_clocked_hours'] ?? 0.0, 2);
                            $total_scheduled = number_format($period_data['total_scheduled_hours'] ?? 0.0, 2);

                            // --- PAY PERIOD TOTAL ROW ---
                            ?>
                            <tr class="wiw-period-total">
                                <td colspan="6" style="background-color: #f0f0ff; font-weight: bold;">
                                    📅 Pay Period: <?php echo esc_html($period_start_date); ?> to <?php echo esc_html($period_end_date); ?>
                                </td>
                                
                                <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo $total_clocked; ?></td>
                                <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo $total_scheduled; ?></td>
                                
                                <td colspan="2" style="background-color: #f0f0ff;"></td>
                            </tr>
                            <?php

                            // --- DAILY RECORD ROWS ---
                            foreach ($period_data['records'] as $time_entry) : 
                                
                                $time_id = $time_entry->id ?? 'N/A';
                                $scheduled_shift_display = $time_entry->scheduled_shift_display ?? 'N/A';
                                
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
                                
                                // Separated Duration Display
                                $clocked_duration = number_format($time_entry->calculated_duration ?? 0.0, 2);
                                $scheduled_duration = number_format($time_entry->scheduled_duration ?? 0.0, 2);
                                
                                $status = (isset($time_entry->approved) && $time_entry->approved) ? 'Approved' : 'Pending';

                                $row_id = 'wiw-raw-' . $global_row_index++;
                                
                                $date_cell_style = ($date_match || $display_end_time === 'Active (N/A)') ? '' : 'style="background-color: #ffe0e0;" title="Clock out date does not match clock in date."';

                                // --- Daily Record Row Display ---
                                ?>
                                <tr class="wiw-daily-record">
                                    <td><?php echo esc_html($time_id); ?></td>
                                    <td <?php echo $date_cell_style; ?>><?php echo esc_html($display_date); ?></td>
                                    <td><?php echo $employee_name; ?></td>
                                    <td><?php echo esc_html($scheduled_shift_display); ?></td>
                                    <td><?php echo esc_html($display_start_time); ?></td>
                                    <td><?php echo esc_html($display_end_time); ?></td>
                                    <td><?php echo esc_html($clocked_duration); ?></td>
                                    <td><?php echo esc_html($scheduled_duration); ?></td>
                                    <td><?php echo esc_html($status); ?></td>
                                    <td>
                                        <button type="button" class="button action-toggle-raw" data-target="<?php echo esc_attr($row_id); ?>">
                                            View Data
                                        </button>
                                    </td>
                                </tr>
                                
                                <tr id="<?php echo esc_attr($row_id); ?>" style="display:none; background-color: #f9f9f9;">
                                    <td colspan="10">
                                        <div style="padding: 10px; border: 1px solid #ccc; max-height: 300px; overflow: auto;">
                                            <strong>Raw API Data:</strong>
                                            <pre style="font-size: 11px;"><?php print_r($time_entry); ?></pre>
                                        </div>
                                    </td>
                                </tr>
                                <?php 
                            endforeach; // End daily records loop
                        endforeach; // End weekly periods loop
                    endforeach; // End employee loop
                    ?>
                </tbody>
            </table>
            <?php endif; // End check for empty grouped_timesheets ?>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('.action-toggle-raw').on('click', function() {
                        var targetId = $(this).data('target');
                        $('#' + targetId).toggle();
                    });
                });
            </script>

            <hr/>
            <details style="border: 1px solid #ccc; background: #fff; padding: 10px; margin-top: 20px;">
                <summary style="cursor: pointer; font-weight: bold; padding: 5px; background: #e0e0e0; margin: -10px;">
                    💡 Click to Expand: Data Reference Legend (Condensed)
                </summary>
                <div style="padding-top: 15px;">
                    <table class="form-table" style="margin-top: 0;">
                        <tbody>
                            <tr>
                                <th scope="row">Record ID</th>
                                <td>**Unique identifier** for the timesheet entry (used for API actions).</td>
                            </tr>
                            <tr>
                                <th scope="row">Date</th>
                                <td>Clock In Date (local timezone). Highlighted **red** if Clock Out occurs on a different day (overnight shift).</td>
                            </tr>
                            <tr>
                                <th scope="row">Employee Name</th>
                                <td>Name retrieved from **`users`** data.</td>
                            </tr>
                            <tr>
                                <th scope="row">Scheduled Shift</th>
                                <td>**Scheduled Start - End Time** (local timezone) retrieved from the corresponding shift record. Shows N/A if no shift is linked.</td>
                            </tr>
                            <tr>
                                <th scope="row">Clock In / Out</th>
                                <td>**Actual Clock In/Out Time** (local timezone). Clock Out shows **"Active (N/A)"** if the shift is open.</td>
                            </tr>
                            <tr>
                                <th scope="row">Hrs Clocked</th>
                                <td>**Total Clocked Hours** (Start Time to End Time based on timesheet data). Pay period totals aggregate this value.</td>
                            </tr>
                            <tr>
                                <th scope="row">Hrs Scheduled</th>
                                <td>**Total Scheduled Hours** (Shift Start to Shift End minus Break). Pay period totals aggregate this value.</td>
                            </tr>
                            <tr>
                                <th scope="row">Status</th>
                                <td>Approval status: **Pending** or **Approved**.</td>
                            </tr>
                            <tr>
                                <th scope="row">Actions</th>
                                <td>Interactive options (currently **View Data**).</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php
        } // End 'else' block for successful data fetch
        ?>
    </div>
    <?php
}

/**
 * Sorts timesheet data first by employee name, then by start time.
 * @param array $times The array of raw time entries.
 * @param array $user_map A map of user IDs to user objects for name lookup.
 * @return array The sorted array of time entries.
 */
private function sort_timesheet_data( $times, $user_map ) {
    usort($times, function($a, $b) use ($user_map) {
        $user_a_id = $a->user_id ?? 0;
        $user_b_id = $b->user_id ?? 0;
        
        // Safety check: Get full name or a fallback string
        $name_a = ($user_map[$user_a_id]->first_name ?? '') . ' ' . ($user_map[$user_a_id]->last_name ?? 'Unknown');
        $name_b = ($user_map[$user_b_id]->first_name ?? '') . ' ' . ($user_map[$user_b_id]->last_name ?? 'Unknown');
        
        // 1. Primary Sort: Employee Name (case-insensitive)
        $name_compare = strcasecmp($name_a, $name_b);

        if ($name_compare !== 0) {
            return $name_compare;
        }

        // 2. Secondary Sort: Start Time (chronological)
        $time_a = $a->start_time ?? '';
        $time_b = $b->start_time ?? '';
        
        return strtotime($time_a) - strtotime($time_b);
    });

    return $times;
}

/**
 * Calculates the duration of a shift in hours (Start Time to End Time minus Break).
 * * @param object $shift_entry The raw shift object.
 * @return float Calculated duration in hours.
 */
private function calculate_shift_duration_in_hours($shift_entry) {
    $start_time_utc = $shift_entry->start_time ?? ''; 
    $end_time_utc = $shift_entry->end_time ?? '';
    $break_minutes = $shift_entry->break ?? 0; 
    
    $duration = 0.0;

    try {
        if (!empty($start_time_utc) && !empty($end_time_utc)) {
            // Use UTC for parsing the raw API data
            $dt_start = new DateTime($start_time_utc, new DateTimeZone('UTC'));
            $dt_end = new DateTime($end_time_utc, new DateTimeZone('UTC'));
            
            $interval = $dt_start->diff($dt_end);
            
            // Convert interval to total seconds
            $total_seconds = $interval->days * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
            
            // Subtract break time (break is in minutes, convert to seconds)
            $total_seconds -= ($break_minutes * 60);
            
            // Convert total seconds to hours (rounded to 2 decimal places)
            $duration = round($total_seconds / 3600, 2);
            
            if ($duration < 0) {
                $duration = 0.0;
            }
        }
    } catch (Exception $e) {
        // Log error if necessary, duration remains 0.0
    }
    
    return $duration;
}

/**
 * Calculates the duration of a timesheet entry in hours.
 * Uses 'length' (hours) or 'duration' (seconds) from the API.
 * @param object $time_entry The raw timesheet object.
 * @return float Calculated duration in hours.
 */
private function calculate_timesheet_duration_in_hours($time_entry) {
    // Try to use 'length' (already in hours)
    $duration = round(($time_entry->length ?? 0), 2);

    // If 'length' is 0, try to use 'duration' (in seconds)
    if ($duration == 0 && isset($time_entry->duration)) {
         $duration = round(($time_entry->duration / 3600), 2);
    }
    
    // Ensure duration is not negative (though unlikely for timesheets)
    return max(0.0, $duration);
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
    $login_result = WIW_API_Client::login( $api_key, $email, $password ); // <-- ARGUMENTS UPDATED

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
 * Uses the /times endpoint.
 * @param array $filters Optional filters (e.g., date ranges).
 * @return object|WP_Error The API response object or a WP_Error object.
 */
private function fetch_timesheets_data($filters = []) {
    $endpoint = 'times'; 
    
    $default_filters = [
        // CRITICAL: Remove 'positions', Add 'shifts'
        'include' => 'users,shifts', 
        
        // Default time frame: last 30 days
        'start' => date('Y-m-d', strtotime('-30 days')),
        'end'   => date('Y-m-d', strtotime('+1 day')),
        'approved' => 0 // Show pending by default
    ];

    $params = array_merge($default_filters, $filters);

    $result = WIW_API_Client::request($endpoint, $params, WIW_API_Client::METHOD_GET);

    return $result;
}

/**
 * Fetches shift data from the When I Work API.
 * Uses the /shifts endpoint to get scheduled shifts.
 * @param array $filters Optional filters (e.g., date ranges).
 * @return object|WP_Error The API response object or a WP_Error object.
 */
private function fetch_shifts_data($filters = []) {
    $endpoint = 'shifts'; 
    
    $default_filters = [
        // CRITICAL: Only include users and sites
        'include' => 'users,sites', 
        
        // Default time frame: past 30 days of shifts
        'start' => date('Y-m-d', strtotime('-30 days')),
        'end'   => date('Y-m-d', strtotime('+1 day')),
    ];

    $params = array_merge($default_filters, $filters);

    $result = WIW_API_Client::request($endpoint, $params, WIW_API_Client::METHOD_GET);

    return $result;
}

/**
 * Fetches user/employee data from the When I Work API.
 * Uses the /users endpoint.
 * @return object|WP_Error The API response object or a WP_Error object.
 */
private function fetch_employees_data() {
    $endpoint = 'users'; 
    
    // Filters to only show employees (not managers/admins) and include positions/locations
    $params = [
        'include' => 'positions,sites', 
        'employment_status' => 1 // Typically '1' means active employee
    ];

    $result = WIW_API_Client::request($endpoint, $params, WIW_API_Client::METHOD_GET);

    return $result;
}

/**
 * Fetches site/location data from the When I Work API.
 * Uses the /sites endpoint (https://apidocs.wheniwork.com/external/index.html#tag/Sites).
 * @return object|WP_Error The API response object or a WP_Error object.
 */
private function fetch_locations_data() {
    $endpoint = 'sites'; 
    // No specific parameters are usually needed for the list of sites
    $params = []; 

    $result = WIW_API_Client::request($endpoint, $params, WIW_API_Client::METHOD_GET);

    return $result;
}

/**
 * Renders the Shifts management page (Admin Area).
 */
public function admin_shifts_page() {
    ?>
    <div class="wrap">
        <h1>📅 When I Work Shifts Dashboard</h1>
        
        <?php 
        $shifts_data = $this->fetch_shifts_data();
        
        if ( is_wp_error( $shifts_data ) ) {
            $error_message = $shifts_data->get_error_message();
            ?>
            <div class="notice notice-error">
                <p><strong>❌ Shift Fetch Error:</strong> <?php echo esc_html($error_message); ?></p>
                <?php if ($shifts_data->get_error_code() === 'wiw_token_missing') : ?>
                    <p>Please go to the <a href="<?php echo esc_url(admin_url('admin.php?page=wiw-timesheets-settings')); ?>">Settings Page</a> to log in and save your session token.</p>
                <?php endif; ?>
            </div>
            <?php
        } else {
            // Extract and map data
            $shifts = isset($shifts_data->shifts) ? $shifts_data->shifts : [];
            $included_users = isset($shifts_data->users) ? $shifts_data->users : [];
            $included_sites = isset($shifts_data->sites) ? $shifts_data->sites : []; 
            
            $user_map = array_column($included_users, null, 'id');
            $site_map = array_column($included_sites, null, 'id'); 
            
            $site_map[0] = (object) ['name' => 'No Assigned Location']; 
            
            // --- PREPROCESSING STEP: Calculate duration and assign to the expected property ---
            foreach ($shifts as &$shift_entry) {
                // Calculate scheduled duration
                $calculated_duration = $this->calculate_shift_duration_in_hours($shift_entry);
                
                // CRITICAL: Assign the scheduled duration to 'calculated_duration'. 
                // The grouping function aggregates this into 'total_clocked_hours' (its default accumulator).
                $shift_entry->calculated_duration = $calculated_duration;
            }
            unset($shift_entry); // Clean up reference
            // --- END PREPROCESSING ---


            // 1. Sort the records 
            $shifts = $this->sort_timesheet_data( $shifts, $user_map );

            // 2. Group the records by employee and week (pay period) 
            $grouped_shifts = $this->group_timesheet_by_pay_period( $shifts, $user_map );
            
            // --- Timezone Setup ---
            $wp_timezone_string = get_option('timezone_string');
            if (empty($wp_timezone_string)) { $wp_timezone_string = 'UTC'; }
            $wp_timezone = new DateTimeZone($wp_timezone_string); 
            $time_format = get_option('time_format') ?: 'g:i A'; 
            // --- End Timezone Setup ---
            
            ?>
            <div class="notice notice-success"><p>✅ Shift data fetched successfully!</p></div>
            
            <h2>Latest Shifts (Grouped by Employee and Pay Period)</h2>

            <?php if (empty($grouped_shifts)) : ?>
                <p>No shift records found within the filtered period.</p>
            <?php else : ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="5%">Shift ID</th>
                        <th width="10%">Date</th>
                        <th width="15%">Employee Name</th>
                        <th width="15%">Location</th>
                        <th width="10%">Start Time</th>
                        <th width="10%">End Time</th>
                        <th width="8%">Breaks (Min)</th> 
                        <th width="8%">Hrs Scheduled</th>
                        <th width="9%">View Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $global_row_index = 0;
                    foreach ($grouped_shifts as $employee_name => $periods) : 
                        
                        // --- EMPLOYEE HEADER ROW ---
                        ?>
                        <tr class="wiw-employee-header">
                            <td colspan="9" style="background-color: #e6e6fa; font-weight: bold; font-size: 1.1em;">
                                👤 Employee: <?php echo esc_html($employee_name); ?>
                            </td>
                        </tr>
                        <?php

                        foreach ($periods as $period_start_date => $period_data) : 
                            $period_end_date = date('Y-m-d', strtotime($period_start_date . ' + 4 days'));
                            
                            // --- PAY PERIOD TOTAL ROW (FIXED KEY) ---
                            // We now retrieve the scheduled hours total from 'total_clocked_hours'
                            $total_scheduled_hours = number_format($period_data['total_clocked_hours'] ?? 0.0, 2);
                            ?>
                            <tr class="wiw-period-total">
                                <td colspan="7" style="background-color: #f0f0ff; font-weight: bold;">
                                    📅 Pay Period: <?php echo esc_html($period_start_date); ?> to <?php echo esc_html($period_end_date); ?>
                                </td>
                                <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo $total_scheduled_hours; ?></td>
                                <td colspan="1" style="background-color: #f0f0ff;"></td>
                            </tr>
                            <?php

                            // --- DAILY RECORD ROWS ---
                            foreach ($period_data['records'] as $shift_entry) : 
                                
                                $shift_id = $shift_entry->id ?? 'N/A';
                                $site_lookup_id = $shift_entry->site_id ?? 0; 

                                $site_obj = $site_map[$site_lookup_id] ?? null; 
                                $location_name = ($site_obj && isset($site_obj->name)) ? esc_html($site_obj->name) : 'No Assigned Location'; 
                                
                                $start_time_utc = $shift_entry->start_time ?? ''; 
                                $end_time_utc = $shift_entry->end_time ?? '';
                                $break_minutes = $shift_entry->break ?? 0; 
                                
                                // --- Date and Time Processing ---
                                $display_date = 'N/A';
                                $display_start_time = 'N/A';
                                $display_end_time = 'N/A';
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
                                
                                // Retrieve duration from the pre-calculated property
                                $duration = number_format($shift_entry->calculated_duration ?? 0.0, 2);
                                
                                $row_id = 'wiw-shift-raw-' . $global_row_index++;
                                
                                $date_cell_style = ($date_match) ? '' : 'style="background-color: #ffe0e0;" title="Shift ends on a different day."';

                                // --- Daily Record Row Display ---
                                ?>
                                <tr class="wiw-daily-record">
                                    <td><?php echo esc_html($shift_id); ?></td>
                                    <td <?php echo $date_cell_style; ?>><?php echo esc_html($display_date); ?></td>
                                    <td><?php echo $employee_name; ?></td>
                                    <td><?php echo $location_name; ?></td> 
                                    <td><?php echo esc_html($display_start_time); ?></td>
                                    <td><?php echo esc_html($display_end_time); ?></td>
                                    <td><?php echo esc_html($break_minutes); ?></td> 
                                    <td><?php echo esc_html($duration); ?></td>
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
                                            <pre style="font-size: 11px;"><?php print_r($shift_entry); ?></pre>
                                        </div>
                                    </td>
                                </div>
                                <?php 
                            endforeach; // End daily records loop
                        endforeach; // End weekly periods loop
                    endforeach; // End employee loop
                    ?>
                </tbody>
            </table>
            <?php endif; // End check for empty grouped_shifts ?>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('.action-toggle-raw').on('click', function() {
                        var targetId = $(this).data('target');
                        $('#' + targetId).toggle();
                    });
                });
            </script>
            
            <?php
        } // End 'else' block for successful data fetch
        ?>
    </div>
    <?php
}

/**
 * Renders the Employees management page (Admin Area).
 */
public function admin_employees_page() {
    ?>
    <div class="wrap">
        <h1>👥 When I Work Employees</h1>
        <p>This page displays all active employee records retrieved from the When I Work API.</p>
        
        <?php 
        $employees_data = $this->fetch_employees_data();
        
        if ( is_wp_error( $employees_data ) ) {
            $error_message = $employees_data->get_error_message();
            ?>
            <div class="notice notice-error">
                <p><strong>❌ Employee Fetch Error:</strong> <?php echo esc_html($error_message); ?></p>
                <?php if ($employees_data->get_error_code() === 'wiw_token_missing') : ?>
                    <p>Please go to the <a href="<?php echo esc_url(admin_url('admin.php?page=wiw-timesheets-settings')); ?>">Settings Page</a> to log in and save your session token.</p>
                <?php endif; ?>
            </div>
            <?php
        } else {
            $users = isset($employees_data->users) ? $employees_data->users : [];
            
            // Define the specific position ID to Name mapping as requested
            $position_name_map = [
                2611462 => 'ECA',
                2611465 => 'RECE',
            ];
            
            if (empty($users)) : ?>
                <div class="notice notice-warning"><p>No active employee records found.</p></div>
            <?php else : ?>

            <div class="notice notice-success"><p>✅ Successfully fetched <?php echo count($users); ?> employees.</p></div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="8%">ID</th>
                        <th width="20%">Name</th>
                        <th width="15%">Email</th>
                        <th width="15%">Positions</th> <th width="15%">Employee Code</th> <th width="12%">Status</th>
                        <th width="15%">View Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $employee_row_index = 0;
                    foreach ($users as $user) : 
                        
                        $user_id = $user->id ?? 'N/A';
                        $full_name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                        
                        // NEW: Get Employee Code
                        $employee_code = $user->employee_code ?? 'N/A';
                        
                        // NEW LOGIC: Process all positions and map them
                        $user_position_ids = $user->positions ?? [];
                        $mapped_positions = [];
                        
                        foreach ($user_position_ids as $pos_id) {
                            if (isset($position_name_map[$pos_id])) {
                                $mapped_positions[] = $position_name_map[$pos_id];
                            }
                        }
                        
                        // Display comma-separated list or 'N/A'
                        $positions_display = !empty($mapped_positions) ? implode(', ', $mapped_positions) : 'N/A';
                        
                        $status = ($user->is_active ?? false) ? 'Active' : 'Inactive';
                        
                        $row_id = 'wiw-employee-raw-' . $employee_row_index++;
                    ?>
                        <tr class="wiw-employee-record">
                            <td><?php echo esc_html($user_id); ?></td>
                            <td style="font-weight: bold;"><?php echo esc_html($full_name); ?></td>
                            <td><?php echo esc_html($user->email ?? 'N/A'); ?></td>
                            <td><?php echo esc_html($positions_display); ?></td> <td><?php echo esc_html($employee_code); ?></td> <td><?php echo esc_html($status); ?></td>
                            <td>
                                <button type="button" class="button action-toggle-raw" data-target="<?php echo esc_attr($row_id); ?>">
                                    View Data
                                </button>
                            </td>
                        </tr>
                        
                        <tr id="<?php echo esc_attr($row_id); ?>" style="display:none; background-color: #f9f9f9;">
                            <td colspan="7"> 
                                <div style="padding: 10px; border: 1px solid #ccc; max-height: 300px; overflow: auto;">
                                    <strong>Raw API Data:</strong>
                                    <pre style="font-size: 11px;"><?php print_r($user); ?></pre>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
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
            
            <?php endif; // End check for empty users
        } // End 'else' block for successful data fetch
        ?>
    </div>
    <?php
}

/**
 * Renders the Locations management page (Admin Area).
 */
public function admin_locations_page() {
    ?>
    <div class="wrap">
        <h1>📍 When I Work Locations</h1>
        <p>This page displays all site records retrieved from the When I Work API.</p>
        
        <?php 
        $locations_data = $this->fetch_locations_data();
        
        if ( is_wp_error( $locations_data ) ) {
            $error_message = $locations_data->get_error_message();
            ?>
            <div class="notice notice-error">
                <p><strong>❌ Location Fetch Error:</strong> <?php echo esc_html($error_message); ?></p>
                <?php if ($locations_data->get_error_code() === 'wiw_token_missing') : ?>
                    <p>Please go to the <a href="<?php echo esc_url(admin_url('admin.php?page=wiw-timesheets-settings')); ?>">Settings Page</a> to log in and save your session token.</p>
                <?php endif; ?>
            </div>
            <?php
        } else {
            $sites = isset($locations_data->sites) ? $locations_data->sites : [];
            
            if (empty($sites)) : ?>
                <div class="notice notice-warning"><p>No location records found.</p></div>
            <?php else : ?>

            <div class="notice notice-success"><p>✅ Successfully fetched <?php echo count($sites); ?> locations.</p></div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="10%">ID</th>
                        <th width="30%">Name</th>
                        <th width="40%">Address</th>
                        <th width="20%">View Data</th> </tr>
                </thead>
                <tbody>
                    <?php 
                    $location_row_index = 0;
                    foreach ($sites as $site) : 
                        $site_id = $site->id ?? 'N/A';
                        $site_name = $site->name ?? 'N/A';
                        
                        // Concatenate address components for display
                        $site_address = trim(
                            ($site->address ?? '') . 
                            (!empty($site->address) && !empty($site->city) ? ', ' : '') .
                            ($site->city ?? '') .
                            (!empty($site->city) && !empty($site->zip_code) ? ' ' : '') .
                            ($site->zip_code ?? '')
                        );
                        
                        // Fallback for address if it's still empty
                        if (empty($site_address)) {
                            $site_address = 'Address Not Provided';
                        }
                        
                        $row_id = 'wiw-location-raw-' . $location_row_index++;
                    ?>
                        <tr class="wiw-location-record">
                            <td><?php echo esc_html($site_id); ?></td>
                            <td style="font-weight: bold;"><?php echo esc_html($site_name); ?></td>
                            <td><?php echo esc_html($site_address); ?></td>
                            <td>
                                <button type="button" class="button action-toggle-raw" data-target="<?php echo esc_attr($row_id); ?>">
                                    View Data
                                </button>
                            </td>
                        </tr>
                        
                        <tr id="<?php echo esc_attr($row_id); ?>" style="display:none; background-color: #f9f9f9;">
                            <td colspan="4">
                                <div style="padding: 10px; border: 1px solid #ccc; max-height: 300px; overflow: auto;">
                                    <strong>Raw API Data:</strong>
                                    <pre style="font-size: 11px;"><?php print_r($site); ?></pre>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Check if the timesheets page script hasn't already defined this
                    if (typeof window.wiwToggleRawListeners === 'undefined') {
                        $('.action-toggle-raw').on('click', function() {
                            var targetId = $(this).data('target');
                            $('#' + targetId).toggle();
                        });
                        // Set a flag so we don't redefine the listener if it's already included on another page (though both pages now include it)
                        window.wiwToggleRawListeners = true; 
                    }
                });
            </script>
            
            <?php endif; // End check for empty sites
        } // End 'else' block for successful data fetch
        ?>
    </div>
    <?php
}

/**
 * Groups timesheet records into weekly pay periods (Monday to Friday) 
 * for each employee and calculates the weekly totals.
 *
 * @param array $times The raw, sorted times array.
 * @param array $user_map Map of user IDs to user objects.
 * @return array The structured timesheet data.
 */
private function group_timesheet_by_pay_period( $times, $user_map ) {
    $grouped_data = [];

    // Define the local WordPress timezone for date calculations
    $wp_timezone_string = get_option('timezone_string');
    if (empty($wp_timezone_string)) {
        $wp_timezone_string = 'UTC';
    }
    $wp_timezone = new DateTimeZone($wp_timezone_string); 

    foreach ( $times as $time_entry ) {
        $user_id = $time_entry->user_id ?? 0;
        $user = $user_map[$user_id] ?? null;
        
        // Skip records we cannot link to a user
        if ( !$user ) continue;

        $employee_name = ($user->first_name ?? '') . ' ' . ($user->last_name ?? 'Unknown');
        $start_time_utc = $time_entry->start_time ?? '';
        
        // --- Aggregation Values ---
        // These properties were calculated in admin_timesheets_page() preprocessing.
        $clocked_duration = $time_entry->calculated_duration ?? 0.0;
        $scheduled_duration = $time_entry->scheduled_duration ?? 0.0;

        // --- Determine Pay Period Start Date (Monday) ---
        $pay_period_start = 'N/A';
        try {
            if (!empty($start_time_utc)) {
                $dt_start_utc = new DateTime($start_time_utc, new DateTimeZone('UTC'));
                $dt_start_utc->setTimezone($wp_timezone);
                
                // Determine the day of the week (1=Mon, 5=Fri, 7=Sun)
                $day_of_week = (int)$dt_start_utc->format('N');
                
                // Adjust to the previous Monday (the start of the pay week)
                if ($day_of_week === 6 || $day_of_week === 7) {
                    // Saturday (6) or Sunday (7) go back to the *previous* Monday
                    $dt_start_utc->modify('last Monday'); 
                } else {
                    // Monday through Friday uses 'this Monday'
                    $dt_start_utc->modify('this Monday');
                }

                // Format the start date as YYYY-MM-DD for the group key
                $pay_period_start = $dt_start_utc->format('Y-m-d');
            }
        } catch (Exception $e) {
            continue; // Skip records with unparsable dates
        }
        // --- End Pay Period Calculation ---

        // Initialize structures if they don't exist
        if ( !isset($grouped_data[$employee_name]) ) {
            $grouped_data[$employee_name] = [];
        }
        if ( !isset($grouped_data[$employee_name][$pay_period_start]) ) {
            $grouped_data[$employee_name][$pay_period_start] = [
                'total_clocked_hours' => 0.0, // Updated key
                'total_scheduled_hours' => 0.0, // New key
                'records' => []
            ];
        }

        // Aggregate total hours and add the record
        $grouped_data[$employee_name][$pay_period_start]['total_clocked_hours'] += $clocked_duration;
        $grouped_data[$employee_name][$pay_period_start]['total_scheduled_hours'] += $scheduled_duration;
        $grouped_data[$employee_name][$pay_period_start]['records'][] = $time_entry;
    }

    return $grouped_data;
}

}

// Instantiate the core class
new WIW_Timesheet_Manager();
