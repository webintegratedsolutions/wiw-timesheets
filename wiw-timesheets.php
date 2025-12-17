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
 * Create or update the WIW Timesheets database tables.
 *
 * - wp_wiw_timesheets        (header: one per employee + week + location)
 * - wp_wiw_timesheet_entries (line items: one per WIW time record)
 */
function wiw_timesheet_manager_install() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $table_timesheets        = $wpdb->prefix . 'wiw_timesheets';
    $table_timesheet_entries = $wpdb->prefix . 'wiw_timesheet_entries';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Header table: one row per Employee + Week + Location
    $sql_timesheets = "CREATE TABLE {$table_timesheets} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        employee_id BIGINT(20) UNSIGNED NOT NULL,
        employee_name VARCHAR(191) NOT NULL DEFAULT '',
        location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        location_name VARCHAR(191) NOT NULL DEFAULT '',
        week_start_date DATE NOT NULL,
        week_end_date DATE NOT NULL,
        total_scheduled_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        total_clocked_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY employee_week_location (employee_id, week_start_date, location_id),
        KEY week_start (week_start_date)
    ) {$charset_collate};";

    // Line items: one row per daily WIW time record
    $sql_timesheet_entries = "CREATE TABLE {$table_timesheet_entries} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        timesheet_id BIGINT(20) UNSIGNED NOT NULL,
        wiw_time_id BIGINT(20) UNSIGNED NOT NULL,
        wiw_shift_id BIGINT(20) UNSIGNED DEFAULT NULL,
        date DATE NOT NULL,
        location_id BIGINT(20) UNSIGNED DEFAULT 0,
        location_name VARCHAR(191) NOT NULL DEFAULT '',
        clock_in DATETIME DEFAULT NULL,
        clock_out DATETIME DEFAULT NULL,
        break_minutes INT(11) NOT NULL DEFAULT 0,
        scheduled_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        clocked_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        notes TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY wiw_time_id (wiw_time_id),
        KEY timesheet_id (timesheet_id),
        KEY entry_date (date)
    ) {$charset_collate};";

    dbDelta( $sql_timesheets );
    dbDelta( $sql_timesheet_entries );
}



/**
 * Enqueue admin CSS for WIW Timesheets plugin.
 */
function wiwts_enqueue_admin_styles( $hook_suffix ) {
    // Optional: only load on your plugin's admin screen.
    // Replace 'toplevel_page_wiw-timesheets' with your actual screen ID / hook.
    if ( $hook_suffix !== 'toplevel_page_wiw-timesheets' ) {
        return;
    }

    wp_enqueue_style(
        'wiwts-admin-styles',
        plugin_dir_url( __FILE__ ) . 'css/wiw-admin.css',
        array(),          // dependencies
        '1.0.0'           // version
    );
}
add_action( 'admin_enqueue_scripts', 'wiwts_enqueue_admin_styles' );/**
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

        // Submenu page for Local Timesheets (local DB view)
        add_submenu_page(
            'wiw-timesheets',
            'Local Timesheets',
            'Local Timesheets',
            'manage_options',
            'wiw-local-timesheets', // Unique slug for this page
            array( $this, 'admin_local_timesheets_page' ) // Function that renders this page
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

// Register_ajax_hooks()
    private function register_ajax_hooks() {
    // ... existing hooks (e.g., for login, settings, timesheet approval)

    // Hook for editing a single timesheet
    add_action( 'wp_ajax_wiw_edit_timesheet_hours', array( $this, 'ajax_edit_timesheet_hours' ) );
}

/**
 * Handles the AJAX request to update a single timesheet record's clock times.
 */
public function ajax_edit_timesheet_hours() {
    // 1. Security Check
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Authorization failed.' ), 403 );
    }

    // You need to replace 'wiw_timesheet_nonce' with the actual nonce name if it's different.
    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'wiw_timesheet_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
    }

    $time_id = isset( $_POST['time_id'] ) ? absint( $_POST['time_id'] ) : 0;
    if ( $time_id === 0 ) {
        wp_send_json_error( array( 'message' => 'Invalid timesheet ID.' ) );
    }

    $start_datetime_full = isset( $_POST['start_datetime_full'] ) ? sanitize_text_field( wp_unslash( $_POST['start_datetime_full'] ) ) : '';
    $end_datetime_full = isset( $_POST['end_datetime_full'] ) ? sanitize_text_field( wp_unslash( $_POST['end_datetime_full'] ) ) : '';

    $start_time_new = isset( $_POST['start_time_new'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time_new'] ) ) : '';
    $end_time_new = isset( $_POST['end_time_new'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time_new'] ) ) : '';

    // 2. Reconstruct the Full Date/Time in UTC
    $update_data = [];

    // --- Timezone Setup ---
    $wp_timezone_string = get_option('timezone_string');
    $wp_timezone = new DateTimeZone($wp_timezone_string ?: 'UTC'); 
    // --- End Timezone Setup ---


    // --- Start Time Reconstruction ---
    if ( ! empty( $start_datetime_full ) && ! empty( $start_time_new ) ) {
        try {
            // Get the date part from the original full datetime string (in local timezone)
            $dt_original_start = new DateTime(explode(' ', $start_datetime_full)[0], $wp_timezone);
            $date_part = $dt_original_start->format('Y-m-d');
            
            // Combine new time (H:i) with the original date (Y-m-d) and add seconds back as 00
            $new_local_datetime_str = "{$date_part} {$start_time_new}:00"; 
            
            // Create the final local datetime object
            $dt_new_local = new DateTime($new_local_datetime_str, $wp_timezone);
            
            // Convert to UTC for the API
            $dt_new_local->setTimezone(new DateTimeZone('UTC'));
            $update_data['start_time'] = $dt_new_local->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            wp_send_json_error( array( 'message' => 'Error parsing new start time: ' . $e->getMessage() ) );
        }
    }

    // --- End Time Reconstruction ---
    if ( ! empty( $end_datetime_full ) && ! empty( $end_time_new ) ) {
        try {
            // Get the date part from the original full datetime string (in local timezone)
            $dt_original_end = new DateTime(explode(' ', $end_datetime_full)[0], $wp_timezone);
            $date_part = $dt_original_end->format('Y-m-d');
            
            // Combine new time (H:i) with the original date (Y-m-d) and add seconds back as 00
            $new_local_datetime_str = "{$date_part} {$end_time_new}:00"; 
            
            // Create the final local datetime object
            $dt_new_local = new DateTime($new_local_datetime_str, $wp_timezone);
            
            // Convert to UTC for the API
            $dt_new_local->setTimezone(new DateTimeZone('UTC'));
            $update_data['end_time'] = $dt_new_local->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            wp_send_json_error( array( 'message' => 'Error parsing new end time: ' . $e->getMessage() ) );
        }
    }
    
    if ( empty( $update_data ) ) {
        wp_send_json_error( array( 'message' => 'No valid time data provided for update.' ) );
    }

    // 3. Call API to Update Timesheet
    // Assumes WIW_API_Client::request is available and is the static method on the API class.
    $endpoint = "times/{$time_id}";
    $result = WIW_API_Client::request( 
        $endpoint, 
        ['time' => $update_data], // API expects 'time' wrapper for PUT
        WIW_API_Client::METHOD_PUT 
    );

    // 4. Send Response
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 
            'message' => 'API Update Failed: ' . $result->get_error_message() 
        ) );
    } else {
        // Success response
        wp_send_json_success( array( 
            'message' => 'Timesheet #' . $time_id . ' successfully updated. Page will reload to show new hours.',
            'new_data' => $result->time
        ) );
    }
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
            $included_users = isset($timesheets_data->users) ? $timesheets_data->users : [];
            $included_shifts = isset($timesheets_data->shifts) ? $timesheets_data->shifts : [];
            $included_sites = isset($timesheets_data->sites) ? $timesheets_data->sites : []; 
            
            $user_map = array_column($included_users, null, 'id');
            $shift_map = array_column($included_shifts, null, 'id');
            $site_map = array_column($included_sites, null, 'id');
            $site_map[0] = (object) ['name' => 'No Assigned Location']; 
            
            // --- Timezone Setup ---
            $wp_timezone_string = get_option('timezone_string');
            if (empty($wp_timezone_string)) { $wp_timezone_string = 'UTC'; }
            $wp_timezone = new DateTimeZone($wp_timezone_string); 
            $time_format = get_option('time_format') ?: 'g:i A'; 

// --- PREPROCESSING ---
if ( method_exists( $this, 'calculate_shift_duration_in_hours' ) ) {
    foreach ( $times as &$time_entry ) {
        $clocked_duration = $this->calculate_timesheet_duration_in_hours( $time_entry );
        $time_entry->calculated_duration = $clocked_duration; 
        
        $shift_id            = $time_entry->shift_id ?? null;
        $scheduled_shift_obj = $shift_map[ $shift_id ] ?? null;

        if ( $scheduled_shift_obj ) {
            $time_entry->scheduled_duration = $this->calculate_shift_duration_in_hours( $scheduled_shift_obj );
            
            $dt_sched_start = new DateTime( $scheduled_shift_obj->start_time, new DateTimeZone( 'UTC' ) );
            $dt_sched_end   = new DateTime( $scheduled_shift_obj->end_time,   new DateTimeZone( 'UTC' ) );
            $dt_sched_start->setTimezone( $wp_timezone );
            $dt_sched_end->setTimezone( $wp_timezone );
            
            $time_entry->scheduled_shift_display = 
                $dt_sched_start->format( $time_format ) . ' - ' . $dt_sched_end->format( $time_format );

            $site_lookup_id = $scheduled_shift_obj->site_id ?? 0;
            $site_obj       = $site_map[ $site_lookup_id ] ?? null; 

            // NEW: store location_id as well, so sync can group by it
            $time_entry->location_id   = $site_lookup_id;
            $time_entry->location_name = ( $site_obj && isset( $site_obj->name ) ) 
                ? esc_html( $site_obj->name ) 
                : 'No Assigned Location';

        } else {
            $time_entry->scheduled_duration      = 0.0;
            $time_entry->scheduled_shift_display = 'N/A';
            $time_entry->location_id             = 0;
            $time_entry->location_name           = 'N/A';
        }
    }
    unset( $time_entry ); 
}

// Sync the latest fetched times into local DB tables (headers + entries)
$this->sync_timesheets_to_local_db( $times, $user_map, $wp_timezone );

            // 1. Sort the records
            $times = $this->sort_timesheet_data( $times, $user_map );

            // 2. Group the records by employee and week (Week of)
            $grouped_timesheets = $this->group_timesheet_by_pay_period( $times, $user_map );
            
            // --- Security Nonce for AJAX ---
            $timesheet_nonce = wp_create_nonce('wiw_timesheet_nonce');
            
            ?>
            <div class="notice notice-success"><p>✅ Timesheet data fetched successfully!</p></div>
            
            <h2>Latest Timesheets (Grouped by Employee and Week of)</h2>

            <?php if (empty($grouped_timesheets)) : ?>
                <p>No timesheet records found within the filtered period.</p>
            <?php else : ?>

            <table class="wp-list-table widefat fixed striped" id="wiw-timesheets-table">
                <thead>
                    <tr>
                        <th width="5%">Record ID</th>
                        <th width="8%">Date</th>
                        <th width="10%">Employee Name</th>
                        <th width="10%">Location</th>
                        <th width="10%">Scheduled Shift</th>
                        <th width="6%">Hrs Scheduled</th>   <th width="8%">Clock In</th>
                        <th width="8%">Clock Out</th>
                        <th width="7%">Breaks (Min -)</th>  <th width="6%">Hrs Clocked</th>     <th width="7%">Status</th>
                        <th width="7%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Pass variables to the include
                    $employee_data = $grouped_timesheets;
                    $global_row_index = 0; 
                    
                    include plugin_dir_path(__FILE__) . 'admin/timesheet-loop.php'; 
                    ?>
                </tbody>
            </table>
            <?php endif; // End check for empty grouped_timesheets ?>
            
            <?php 
            include plugin_dir_path(__FILE__) . 'admin/timesheet-dashboard-script.php'; 
            ?>

            <hr/>
            <details style="border: 1px solid #ccc; background: #fff; padding: 10px; margin-top: 20px;">
                <summary style="cursor: pointer; font-weight: bold; padding: 5px; background: #e0e0e0; margin: -10px;">
                    💡 Click to Expand: Data Reference Legend (Condensed)
                </summary>
                <div style="padding-top: 15px;">
                    <table class="form-table" style="margin-top: 0;">
                        <tbody>
                            <tr><th scope="row">Record ID</th><td>**Unique identifier** for the timesheet entry (used for API actions).</td></tr>
                            <tr><th scope="row">Date</th><td>Clock In Date (local timezone). Highlighted **red** if Clock Out occurs on a different day (overnight shift).</td></tr>
                            <tr><th scope="row">Employee Name</th><td>Name retrieved from **`users`** data.</td></tr>
                            <tr><th scope="row">Location</th><td>**Assigned Location** retrieved from the corresponding shift record.</td></tr>
                            <tr><th scope="row">Scheduled Shift</th><td>**Scheduled Start - End Time** (local timezone). Shows N/A if no shift is linked.</td></tr>
                            <tr><th scope="row">Hrs Scheduled</th><td>**Total Scheduled Hours**. Week of totals aggregate this value.</td></tr>
                            <tr><th scope="row">Clock In / Out</th><td>**Actual Clock In/Out Time** (local timezone). Clock Out shows **"Active (N/A)"** if the shift is open.</td></tr>
                            <tr><th scope="row">Breaks (Min -)</th><td>**Time deducted for breaks**, derived from the `break_hours` raw data converted to minutes.</td></tr>
                            <tr><th scope="row">Hrs Clocked</th><td>**Total Clocked Hours**. Week of totals aggregate this value.</td></tr>
                            <tr><th scope="row">Status</th><td>Approval status: **Pending** or **Approved**.</td></tr>
                            <tr><th scope="row">Actions</th><td>Interactive options (**Data** and **Edit/Approve**).</td></tr>
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
 * Syncs fetched timesheet data from the WIW API into local DB tables:
 * - wp_wiw_timesheets        (header: one per employee + week + location)
 * - wp_wiw_timesheet_entries (line items: one per WIW time record)
 *
 * This does NOT change any UI or workflow; it just mirrors data locally.
 *
 * @param array        $times       The raw times array (with calculated_duration, scheduled_duration, location fields).
 * @param array        $user_map    Map of user IDs to user objects.
 * @param DateTimeZone $wp_timezone WordPress timezone object.
 */
private function sync_timesheets_to_local_db( $times, $user_map, $wp_timezone ) {
    global $wpdb;

    if ( empty( $times ) ) {
        return;
    }

    $table_timesheets        = $wpdb->prefix . 'wiw_timesheets';
    $table_timesheet_entries = $wpdb->prefix . 'wiw_timesheet_entries';

    $grouped = [];

    // 1. Group by employee_id + week_start + location_id
    foreach ( $times as $time_entry ) {
        $user_id = isset( $time_entry->user_id ) ? (int) $time_entry->user_id : 0;
        if ( ! $user_id || ! isset( $user_map[ $user_id ] ) ) {
            continue;
        }

        $user          = $user_map[ $user_id ];
        $employee_name = trim( ( $user->first_name ?? '' ) . ' ' . ( $user->last_name ?? 'Unknown' ) );

        $start_time_utc = $time_entry->start_time ?? '';
        if ( empty( $start_time_utc ) ) {
            continue;
        }

        $location_id   = isset( $time_entry->location_id ) ? (int) $time_entry->location_id : 0;
        $location_name = isset( $time_entry->location_name ) ? (string) $time_entry->location_name : '';

        // --- Compute "Week of" (Monday-based) like group_timesheet_by_pay_period() ---
        try {
            $dt = new DateTime( $start_time_utc, new DateTimeZone( 'UTC' ) );
            $dt->setTimezone( $wp_timezone ); // local time for day-of-week

            $day_of_week_N  = (int) $dt->format( 'N' ); // 1=Mon, 7=Sun
            $days_to_modify = 0;

            if ( $day_of_week_N >= 1 && $day_of_week_N <= 5 ) {
                // Mon–Fri: go back to that week's Monday
                $days_to_modify = - ( $day_of_week_N - 1 );
            } else {
                // Sat/Sun: go forward to next Monday
                $days_to_modify = 8 - $day_of_week_N;
            }

            if ( 0 !== $days_to_modify ) {
                $dt->modify( "{$days_to_modify} days" );
            }

            $week_start = $dt->format( 'Y-m-d' );
        } catch ( Exception $e ) {
            continue;
        }
        // -----------------------------------------------------------------------

        // Key includes location_id
        $key = $user_id . '|' . $week_start . '|' . $location_id;

        if ( ! isset( $grouped[ $key ] ) ) {
            $grouped[ $key ] = [
                'employee_id'           => $user_id,
                'employee_name'         => $employee_name,
                'location_id'           => $location_id,
                'location_name'         => $location_name,
                'week_start_date'       => $week_start,
                'records'               => [],
                'total_clocked_hours'   => 0.0,
                'total_scheduled_hours' => 0.0,
            ];
        }

        $clocked_duration   = (float) ( $time_entry->calculated_duration   ?? 0.0 );
        $scheduled_duration = (float) ( $time_entry->scheduled_duration    ?? 0.0 );

        $grouped[ $key ]['total_clocked_hours']   += $clocked_duration;
        $grouped[ $key ]['total_scheduled_hours'] += $scheduled_duration;
        $grouped[ $key ]['records'][]             = $time_entry;
    }

    if ( empty( $grouped ) ) {
        return;
    }

    $now = current_time( 'mysql' );

    // 2. Upsert headers + entries
    foreach ( $grouped as $bundle ) {
        $employee_id           = $bundle['employee_id'];
        $employee_name         = $bundle['employee_name'];
        $location_id           = $bundle['location_id'];
        $location_name         = $bundle['location_name'];
        $week_start_date       = $bundle['week_start_date'];
        $week_end_date         = date( 'Y-m-d', strtotime( $week_start_date . ' +4 days' ) ); // Mon–Fri
        $total_clocked_hours   = round( $bundle['total_clocked_hours'],   2 );
        $total_scheduled_hours = round( $bundle['total_scheduled_hours'], 2 );
        $records               = $bundle['records'];

        // --- Header upsert: one per employee + week_start_date + location_id ---
        $header_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_timesheets} 
                 WHERE employee_id = %d AND week_start_date = %s AND location_id = %d",
                $employee_id,
                $week_start_date,
                $location_id
            )
        );

        $header_data = [
            'employee_id'           => $employee_id,
            'employee_name'         => $employee_name,
            'location_id'           => $location_id,
            'location_name'         => $location_name,
            'week_start_date'       => $week_start_date,
            'week_end_date'         => $week_end_date,
            'total_scheduled_hours' => $total_scheduled_hours,
            'total_clocked_hours'   => $total_clocked_hours,
            'updated_at'            => $now,
        ];

        if ( $header_id ) {
            $wpdb->update(
                $table_timesheets,
                $header_data,
                [ 'id' => $header_id ]
            );
        } else {
            $header_data['status']     = 'pending';
            $header_data['created_at'] = $now;

            $wpdb->insert(
                $table_timesheets,
                $header_data
            );
            $header_id = (int) $wpdb->insert_id;
        }

        if ( ! $header_id ) {
            // If header can't be created, skip its records
            continue;
        }

        // --- Entries upsert: one per WIW time record ---
        foreach ( $records as $time_entry ) {
            $wiw_time_id = isset( $time_entry->id ) ? (int) $time_entry->id : 0;
            if ( ! $wiw_time_id ) {
                continue;
            }

            $start_time_utc = $time_entry->start_time ?? '';
            if ( empty( $start_time_utc ) ) {
                continue;
            }

            $end_time_utc    = $time_entry->end_time ?? '';
            $break_minutes   = isset( $time_entry->break ) ? (int) $time_entry->break : 0;
            $scheduled_hours = (float) ( $time_entry->scheduled_duration  ?? 0.0 );
            $clocked_hours   = (float) ( $time_entry->calculated_duration ?? 0.0 );
            $location_id     = isset( $time_entry->location_id ) ? (int) $time_entry->location_id : 0;
            $location_name   = isset( $time_entry->location_name ) ? (string) $time_entry->location_name : '';

            // Convert to local date/time
            try {
                $dt_start_utc = new DateTime( $start_time_utc, new DateTimeZone( 'UTC' ) );
                $dt_start_utc->setTimezone( $wp_timezone );
                $local_date     = $dt_start_utc->format( 'Y-m-d' );
                $local_clock_in = $dt_start_utc->format( 'Y-m-d H:i:s' );
            } catch ( Exception $e ) {
                continue;
            }

            $local_clock_out = null;
            if ( ! empty( $end_time_utc ) ) {
                try {
                    $dt_end_utc = new DateTime( $end_time_utc, new DateTimeZone( 'UTC' ) );
                    $dt_end_utc->setTimezone( $wp_timezone );
                    $local_clock_out = $dt_end_utc->format( 'Y-m-d H:i:s' );
                } catch ( Exception $e ) {
                    $local_clock_out = null;
                }
            }

            // Upsert entry by wiw_time_id
            $entry_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table_timesheet_entries} WHERE wiw_time_id = %d",
                    $wiw_time_id
                )
            );

            $entry_data = [
                'timesheet_id'    => $header_id,
                'wiw_time_id'     => $wiw_time_id,
                'wiw_shift_id'    => isset( $time_entry->shift_id ) ? (int) $time_entry->shift_id : null,
                'date'            => $local_date,
                'location_id'     => $location_id,
                'location_name'   => $location_name,
                'clock_in'        => $local_clock_in,
                'clock_out'       => $local_clock_out,
                'break_minutes'   => $break_minutes,
                'scheduled_hours' => round( $scheduled_hours, 2 ),
                'clocked_hours'   => round( $clocked_hours, 2 ),
                'status'          => 'pending',
                'updated_at'      => $now,
            ];

            if ( $entry_id ) {
                $wpdb->update(
                    $table_timesheet_entries,
                    $entry_data,
                    [ 'id' => $entry_id ]
                );
            } else {
                $entry_data['created_at'] = $now;
                $wpdb->insert(
                    $table_timesheet_entries,
                    $entry_data
                );
            }
        }
    }
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
        // FIX: Include 'sites' to get location data
        'include' => 'users,shifts,sites', 
        
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

            // 2. Group the records by employee and week (Week of) 
            $grouped_shifts = $this->group_timesheet_by_pay_period( $shifts, $user_map );
            
            // --- Timezone Setup ---
            $wp_timezone_string = get_option('timezone_string');
            if (empty($wp_timezone_string)) { $wp_timezone_string = 'UTC'; }
            $wp_timezone = new DateTimeZone($wp_timezone_string); 
            $time_format = get_option('time_format') ?: 'g:i A'; 
            // --- End Timezone Setup ---
            
            ?>
            <div class="notice notice-success"><p>✅ Shift data fetched successfully!</p></div>
            
            <h2>Latest Shifts (Grouped by Employee and Week of)</h2>

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
                            
                            // --- Week of TOTAL ROW (FIXED KEY) ---
                            // We now retrieve the scheduled hours total from 'total_clocked_hours'
                            $total_scheduled_hours = number_format($period_data['total_clocked_hours'] ?? 0.0, 2);
                            ?>
                            <tr class="wiw-period-total">
                                <td colspan="7" style="background-color: #f0f0ff; font-weight: bold;">
                                    📅 Week of: <?php echo esc_html($period_start_date); ?> to <?php echo esc_html($period_end_date); ?>
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
 * Renders the Local Timesheets view (Admin Area) from the local DB tables.
 *
 * This is read-only for now: one list of timesheet headers, and an optional
 * detail view showing the daily entries for a selected timesheet.
 */
public function admin_local_timesheets_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'wiw-timesheets' ) );
    }

    global $wpdb;

    $table_timesheets        = $wpdb->prefix . 'wiw_timesheets';
    $table_timesheet_entries = $wpdb->prefix . 'wiw_timesheet_entries';

    // Selected timesheet ID for detail view (if any)
    $selected_id = isset( $_GET['timesheet_id'] ) ? (int) $_GET['timesheet_id'] : 0;

    ?>
    <div class="wrap">
        <h1>📁 Local Timesheets (Database View)</h1>
        <p>This page displays timesheets stored locally in WordPress, grouped by Employee, Week, and Location.</p>
    <?php

    // If a specific timesheet is selected, show header info + entries
    if ( $selected_id > 0 ) {
        $header = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_timesheets} WHERE id = %d",
                $selected_id
            )
        );

        if ( $header ) {
            // Back link to list
            $list_url = remove_query_arg( 'timesheet_id' );
            ?>
            <p>
                <a href="<?php echo esc_url( $list_url ); ?>" class="button">← Back to Local Timesheets List</a>
            </p>

            <h2>Timesheet #<?php echo esc_html( $header->id ); ?> Details</h2>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                    <tr>
                        <th scope="row" style="width: 200px;">Employee</th>
                        <td><?php echo esc_html( $header->employee_name ); ?> (ID: <?php echo esc_html( $header->employee_id ); ?>)</td>
                    </tr>
                    <tr>
                        <th scope="row">Location</th>
                        <td>
                            <?php echo esc_html( $header->location_name ); ?>
                            <?php if ( ! empty( $header->location_id ) ) : ?>
                                (ID: <?php echo esc_html( $header->location_id ); ?>)
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Week of</th>
                        <td>
                            <?php echo esc_html( $header->week_start_date ); ?>
                            <?php if ( ! empty( $header->week_end_date ) ) : ?>
                                &nbsp;to&nbsp;<?php echo esc_html( $header->week_end_date ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Totals</th>
                        <td>
                            Scheduled: <?php echo esc_html( number_format( (float) $header->total_scheduled_hours, 2 ) ); ?> hrs,
                            Clocked: <?php echo esc_html( number_format( (float) $header->total_clocked_hours, 2 ) ); ?> hrs
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Status</th>
                        <td><?php echo esc_html( $header->status ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Created / Updated</th>
                        <td>
                            Created: <?php echo esc_html( $header->created_at ); ?><br/>
                            Updated: <?php echo esc_html( $header->updated_at ); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin-top: 30px;">Daily Entries</h3>
            <?php
            $entries = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_timesheet_entries} 
                     WHERE timesheet_id = %d 
                     ORDER BY date ASC, clock_in ASC",
                    $header->id
                )
            );

            if ( empty( $entries ) ) : ?>
                <p>No entries found for this timesheet.</p>
            <?php else : ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="10%">Date</th>
                            <th width="20%">Clock In</th>
                            <th width="20%">Clock Out</th>
                            <th width="10%">Break (min)</th>
                            <th width="10%">Sched. Hrs</th>
                            <th width="10%">Clocked Hrs</th>
                            <th width="10%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $entries as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $entry->date ); ?></td>
                                <td><?php echo esc_html( $entry->clock_in ); ?></td>
                                <td><?php echo esc_html( $entry->clock_out ); ?></td>
                                <td><?php echo esc_html( $entry->break_minutes ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $entry->scheduled_hours, 2 ) ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $entry->clocked_hours, 2 ) ); ?></td>
                                <td><?php echo esc_html( $entry->status ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php
        } else {
            // Invalid ID or not found
            ?>
            <div class="notice notice-error">
                <p>Timesheet not found.</p>
            </div>
            <?php
        }

        echo '</div>'; // .wrap
        return;
    }

    // No specific ID selected: show list of headers
    $headers = $wpdb->get_results(
        "SELECT * FROM {$table_timesheets} 
         ORDER BY week_start_date DESC, employee_name ASC, location_name ASC 
         LIMIT 200"
    );

    if ( empty( $headers ) ) : ?>
        <div class="notice notice-warning">
            <p>No local timesheets found. Visit the main WIW Timesheets Dashboard to fetch and sync data.</p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="6%">ID</th>
                    <th width="16%">Week of</th>
                    <th width="18%">Employee</th>
                    <th width="20%">Location</th>
                    <th width="12%">Sched. Hrs</th>
                    <th width="12%">Clocked Hrs</th>
                    <th width="10%">Status</th>
                    <th width="6%">View</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $headers as $row ) : 
                    $detail_url = add_query_arg(
                        array( 'timesheet_id' => (int) $row->id ),
                        menu_page_url( 'wiw-local-timesheets', false )
                    );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $row->id ); ?></td>
                        <td>
                            <?php echo esc_html( $row->week_start_date ); ?>
                            <?php if ( ! empty( $row->week_end_date ) ) : ?>
                                &nbsp;to&nbsp;<?php echo esc_html( $row->week_end_date ); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row->employee_name ); ?></td>
                        <td>
                            <?php echo esc_html( $row->location_name ); ?>
                            <?php if ( ! empty( $row->location_id ) ) : ?>
                                <br/><small>(ID: <?php echo esc_html( $row->location_id ); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( number_format( (float) $row->total_scheduled_hours, 2 ) ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $row->total_clocked_hours, 2 ) ); ?></td>
                        <td><?php echo esc_html( $row->status ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    </div><!-- .wrap -->
    <?php
}


/**
 * Groups timesheet records into weekly Week ofs (Monday to Friday) 
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
        
        if ( !$user ) continue;

        $employee_name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? 'Unknown'));
        $start_time_utc = $time_entry->start_time ?? '';
        
        $clocked_duration = $time_entry->calculated_duration ?? 0.0;
        $scheduled_duration = $time_entry->scheduled_duration ?? 0.0;

        // --- Determine Week of Start Date (Monday) ---
        $pay_period_start = 'N/A';
        try {
            if (!empty($start_time_utc)) {
                // 1. Create DateTime object from UTC API time
                $dt = new DateTime($start_time_utc, new DateTimeZone('UTC'));
                // 2. Convert to local WordPress time for correct day of week calculation
                $dt->setTimezone($wp_timezone); 
                
                $day_of_week_N = (int)$dt->format('N'); // 1=Mon, 7=Sun
                $days_to_modify = 0;
                
                // 3. Apply deterministic shift calculation based on local day of week
                if ($day_of_week_N >= 1 && $day_of_week_N <= 5) {
                    // Monday (1) through Friday (5): Go back to current week's Monday.
                    // e.g., Wednesday (3): 3 - 1 = 2. Go back 2 days.
                    $days_to_modify = -($day_of_week_N - 1);
                } else { 
                    // Saturday (6) or Sunday (7): Go forward to next week's Monday.
                    // e.g., Sat (6): 8 - 6 = 2. Go forward 2 days.
                    // e.g., Sun (7): 8 - 7 = 1. Go forward 1 day.
                    $days_to_modify = 8 - $day_of_week_N;
                }
                
                // 4. Modify the date to the calculated Monday start date
                if ($days_to_modify !== 0) {
                    $dt->modify("{$days_to_modify} days");
                }

                $pay_period_start = $dt->format('Y-m-d');
            }
        } catch (Exception $e) {
            continue;
        }
        // --- End Week of Calculation ---

        // Initialize structures if they don't exist
        if ( !isset($grouped_data[$employee_name]) ) {
            $grouped_data[$employee_name] = [];
        }
        if ( !isset($grouped_data[$employee_name][$pay_period_start]) ) {
            $grouped_data[$employee_name][$pay_period_start] = [
                'total_clocked_hours' => 0.0,
                'total_scheduled_hours' => 0.0,
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

/**
 * Run installation routine on plugin activation.
 */
register_activation_hook( __FILE__, 'wiw_timesheet_manager_install' );