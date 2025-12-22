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

// Define plugin URL for assets (CSS/JS)
if ( ! defined( 'WIW_PLUGIN_URL' ) ) {
    define( 'WIW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// 💥 IMPORTANT: Include the When I Work API Wrapper file
require_once WIW_PLUGIN_PATH . 'includes/wheniwork.php';

// Include the admin enqueue file for styles/scripts
require_once WIW_PLUGIN_PATH . 'includes/admin-enqueue.php';

// Include the installation file for DB setup
require_once WIW_PLUGIN_PATH . 'includes/install.php';

// Include the admin settings trait
require_once WIW_PLUGIN_PATH . 'includes/admin-settings.php';

// Include the admin login handler
require_once WIW_PLUGIN_PATH . 'includes/admin-login-handler.php';

// Include the timesheet sync trait
require_once WIW_PLUGIN_PATH . 'includes/timesheet-helpers.php';

// Include the timesheet helpers trait
require_once WIW_PLUGIN_PATH . 'includes/timesheet-sync.php';


/**
* Core Plugin Class
 */
class WIW_Timesheet_Manager {

    // Use traits for modular functionality
    use WIW_Timesheet_Admin_Settings_Trait;

    // Include timesheet helper methods
    use WIW_Timesheet_Helpers_Trait;

    // Include timesheet sync methods
    use WIW_Timesheet_Sync_Trait;

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

        // 4. NEW: AJAX for local entry hours update (Local Timesheets view)
        add_action( 'wp_ajax_wiw_local_update_entry', array( $this, 'ajax_local_update_entry' ) );

        // 5. Login handler
        add_action( 'admin_post_wiw_login_handler', 'wiwts_handle_wiw_login' );

        // 6. Reset local timesheet from API (admin-post)
        add_action( 'admin_post_wiw_reset_local_timesheet', array( $this, 'handle_reset_local_timesheet' ) );

        // 7. Finalize local timesheet (admin-post)
        add_action( 'admin_post_wiw_finalize_local_timesheet', array( $this, 'handle_finalize_local_timesheet' ) );

        // ✅ Register additional AJAX hooks (THIS was missing)
        $this->register_ajax_hooks();
    }

    /**
     * Normalize a local DATETIME string to minute precision (YYYY-mm-dd HH:ii).
     * The UI edits HH:MM, while the WIW API may include seconds. We treat
     * "seconds-only" differences as no change for logging purposes.
     */
    private function normalize_datetime_to_minute( $datetime ) {
        $datetime = is_string( $datetime ) ? trim( $datetime ) : '';
        if ( $datetime === '' ) {
            return '';
        }
        if ( strlen( $datetime ) >= 16 ) {
            return substr( $datetime, 0, 16 );
        }
        return $datetime;
    }

    private function insert_local_edit_log( $args ) {
        global $wpdb;

        $table_logs = $wpdb->prefix . 'wiw_timesheet_edit_logs';

        $wpdb->insert(
            $table_logs,
            array(
                'timesheet_id'           => (int) ( $args['timesheet_id'] ?? 0 ),
                'entry_id'               => (int) ( $args['entry_id'] ?? 0 ),
                'wiw_time_id'            => (int) ( $args['wiw_time_id'] ?? 0 ),
                'edit_type'              => (string) ( $args['edit_type'] ?? '' ),
                'old_value'              => (string) ( $args['old_value'] ?? '' ),
                'new_value'              => (string) ( $args['new_value'] ?? '' ),
                'edited_by_user_id'      => (int) ( $args['edited_by_user_id'] ?? 0 ),
                'edited_by_user_login'   => (string) ( $args['edited_by_user_login'] ?? '' ),
                'edited_by_display_name' => (string) ( $args['edited_by_display_name'] ?? '' ),
                'employee_id'            => (int) ( $args['employee_id'] ?? 0 ),
                'employee_name'          => (string) ( $args['employee_name'] ?? '' ),
                'location_id'            => (int) ( $args['location_id'] ?? 0 ),
                'location_name'          => (string) ( $args['location_name'] ?? '' ),
                'week_start_date'        => (string) ( $args['week_start_date'] ?? '' ),
                'created_at'             => (string) ( $args['created_at'] ?? current_time( 'mysql' ) ),
            ),
            array(
                '%d','%d','%d',
                '%s',
                '%s','%s',
                '%d','%s','%s',
                '%d','%s',
                '%d','%s',
                '%s',
                '%s'
            )
        );
    }

    // Register_ajax_hooks()
    private function register_ajax_hooks() {
        // Edit a single timesheet record's clock times (API)
        add_action( 'wp_ajax_wiw_edit_timesheet_hours', array( $this, 'ajax_edit_timesheet_hours' ) );

        // ✅ Align approve AJAX action names used by the JS with the existing handler
        add_action( 'wp_ajax_wiw_approve_single_timesheet', array( $this, 'handle_approve_timesheet' ) );
        add_action( 'wp_ajax_wiw_approve_timesheet_period', array( $this, 'handle_approve_timesheet' ) );

        // Keep legacy / existing action if you still use it anywhere
        add_action( 'wp_ajax_wiw_approve_timesheet', array( $this, 'handle_approve_timesheet' ) );

        // === WIWTS APPROVE TIME RECORD AJAX HOOK ADD START ===
add_action( 'wp_ajax_wiw_local_approve_entry', array( $this, 'ajax_local_approve_entry' ) );
// === WIWTS APPROVE TIME RECORD AJAX HOOK ADD END ===

    }

    /**
     * Handles the AJAX request to update a single timesheet record's clock times.
     */
    public function ajax_edit_timesheet_hours() {
        // 1. Security Check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Authorization failed.' ), 403 );
        }

        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'wiw_timesheet_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        $time_id = isset( $_POST['time_id'] ) ? absint( $_POST['time_id'] ) : 0;
        if ( $time_id === 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid timesheet ID.' ) );
        }

        $start_datetime_full = isset( $_POST['start_datetime_full'] ) ? sanitize_text_field( wp_unslash( $_POST['start_datetime_full'] ) ) : '';
        $end_datetime_full   = isset( $_POST['end_datetime_full'] ) ? sanitize_text_field( wp_unslash( $_POST['end_datetime_full'] ) ) : '';

        $start_time_new = isset( $_POST['start_time_new'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time_new'] ) ) : '';
        $end_time_new   = isset( $_POST['end_time_new'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time_new'] ) ) : '';

        $update_data = array();

        $wp_timezone_string = get_option('timezone_string');
        $wp_timezone        = new DateTimeZone($wp_timezone_string ?: 'UTC');

        if ( ! empty( $start_datetime_full ) && ! empty( $start_time_new ) ) {
            try {
                $dt_original_start = new DateTime(explode(' ', $start_datetime_full)[0], $wp_timezone);
                $date_part = $dt_original_start->format('Y-m-d');

                $new_local_datetime_str = "{$date_part} {$start_time_new}:00";
                $dt_new_local = new DateTime($new_local_datetime_str, $wp_timezone);

                $dt_new_local->setTimezone(new DateTimeZone('UTC'));
                $update_data['start_time'] = $dt_new_local->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                wp_send_json_error( array( 'message' => 'Error parsing new start time: ' . $e->getMessage() ) );
            }
        }

        if ( ! empty( $end_datetime_full ) && ! empty( $end_time_new ) ) {
            try {
                $dt_original_end = new DateTime(explode(' ', $end_datetime_full)[0], $wp_timezone);
                $date_part = $dt_original_end->format('Y-m-d');

                $new_local_datetime_str = "{$date_part} {$end_time_new}:00";
                $dt_new_local = new DateTime($new_local_datetime_str, $wp_timezone);

                $dt_new_local->setTimezone(new DateTimeZone('UTC'));
                $update_data['end_time'] = $dt_new_local->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                wp_send_json_error( array( 'message' => 'Error parsing new end time: ' . $e->getMessage() ) );
            }
        }

        if ( empty( $update_data ) ) {
            wp_send_json_error( array( 'message' => 'No valid time data provided for update.' ) );
        }

        $endpoint = "times/{$time_id}";
        $result = WIW_API_Client::request(
            $endpoint,
            array( 'time' => $update_data ),
            WIW_API_Client::METHOD_PUT
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => 'API Update Failed: ' . $result->get_error_message()
            ) );
        } else {
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
                $times          = isset($timesheets_data->times) ? $timesheets_data->times : array();
                $included_users = isset($timesheets_data->users) ? $timesheets_data->users : array();
                $included_shifts= isset($timesheets_data->shifts) ? $timesheets_data->shifts : array();
                $included_sites = isset($timesheets_data->sites) ? $timesheets_data->sites : array();

                $user_map  = array_column($included_users, null, 'id');
                $shift_map = array_column($included_shifts, null, 'id');
                $site_map  = array_column($included_sites, null, 'id');
                $site_map[0] = (object) array('name' => 'No Assigned Location');

                $wp_timezone_string = get_option('timezone_string');
                if (empty($wp_timezone_string)) { $wp_timezone_string = 'UTC'; }
                $wp_timezone = new DateTimeZone($wp_timezone_string);
                $time_format = get_option('time_format') ?: 'g:i A';

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

                $this->sync_timesheets_to_local_db( $times, $user_map, $wp_timezone, $shift_map );

                $times = $this->sort_timesheet_data( $times, $user_map );
                $grouped_timesheets = $this->group_timesheet_by_pay_period( $times, $user_map );

                $timesheet_nonce = wp_create_nonce('wiw_timesheet_nonce');
                ?>
                <div class="notice notice-success"><p>✅ Timesheet data fetched successfully!</p></div>

                <h2>Latest Timesheets (Grouped by Employee and Pay Period)</h2>

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
                            <th width="6%">Hrs Scheduled</th>
                            <th width="8%">Clock In</th>
                            <th width="8%">Clock Out</th>
                            <th width="7%">Breaks (Min -)</th>
                            <th width="6%">Hrs Clocked</th>
                            <th width="7%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $employee_data = $grouped_timesheets;
                        $global_row_index = 0;
                        include plugin_dir_path(__FILE__) . 'admin/timesheet-loop.php';
                        ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php include plugin_dir_path(__FILE__) . 'admin/timesheet-dashboard-script.php'; ?>

                <hr/>
                <details style="border: 1px solid #ccc; background: #fff; padding: 10px; margin-top: 20px;">
                    <summary style="cursor: pointer; font-weight: bold; padding: 5px; background: #e0e0e0; margin: -10px;">
                        💡 Click to Expand: Data Reference Legend (Condensed)
                    </summary>
                    <div style="padding-top: 15px;">
                        <table class="form-table" style="margin-top: 0;">
                            <tbody>
                                <tr><th scope="row">Record ID</th><td>Unique identifier for the timesheet entry (used for API actions).</td></tr>
                                <tr><th scope="row">Date</th><td>Clock In Date (local timezone). Highlighted red if Clock Out occurs on a different day (overnight shift).</td></tr>
                                <tr><th scope="row">Employee Name</th><td>Name retrieved from users data.</td></tr>
                                <tr><th scope="row">Location</th><td>Assigned Location retrieved from the corresponding shift record.</td></tr>
                                <tr><th scope="row">Scheduled Shift</th><td>Scheduled Start - End Time (local timezone). Shows N/A if no shift is linked.</td></tr>
                                <tr><th scope="row">Hrs Scheduled</th><td>Total Scheduled Hours. Pay period of totals aggregate this value.</td></tr>
                                <tr><th scope="row">Clock In / Out</th><td>Actual Clock In/Out Time (local timezone). Clock Out shows Active (N/A) if the shift is open.</td></tr>
                                <tr><th scope="row">Breaks (Min -)</th><td>Time deducted for breaks, derived from the break_hours raw data converted to minutes.</td></tr>
                                <tr><th scope="row">Hrs Clocked</th><td>Total Clocked Hours. Pay period of totals aggregate this value.</td></tr>
                                <tr><th scope="row">Status</th><td>Approval status: Pending or Approved.</td></tr>
                                <tr><th scope="row">Actions</th><td>Interactive options (Data and Edit/Approve).</td></tr>
                            </tbody>
                        </table>
                    </div>
                </details>
                <?php
            }
            ?>
        </div>
        <?php
    }

    /**
     * Sorts timesheet data first by employee name, then by start time.
     */
    private function sort_timesheet_data( $times, $user_map ) {
        usort($times, function($a, $b) use ($user_map) {
            $user_a_id = $a->user_id ?? 0;
            $user_b_id = $b->user_id ?? 0;

            $name_a = ($user_map[$user_a_id]->first_name ?? '') . ' ' . ($user_map[$user_a_id]->last_name ?? 'Unknown');
            $name_b = ($user_map[$user_b_id]->first_name ?? '') . ' ' . ($user_map[$user_b_id]->last_name ?? 'Unknown');

            $name_compare = strcasecmp($name_a, $name_b);
            if ($name_compare !== 0) {
                return $name_compare;
            }

            $time_a = $a->start_time ?? '';
            $time_b = $b->start_time ?? '';

            return strtotime($time_a) - strtotime($time_b);
        });

        return $times;
    }

    private function fetch_timesheets_data($filters = array()) {
        $endpoint = 'times';

        $default_filters = array(
            'include'   => 'users,shifts,sites',
            'start'     => date('Y-m-d', strtotime('-30 days')),
            'end'       => date('Y-m-d', strtotime('+1 day')),
            'approved'  => 0
        );

        $params = array_merge($default_filters, $filters);
        return WIW_API_Client::request($endpoint, $params, WIW_API_Client::METHOD_GET);
    }

    private function fetch_shifts_data($filters = array()) {
        $endpoint = 'shifts';

        $default_filters = array(
            'include' => 'users,sites',
            'start'   => date('Y-m-d', strtotime('-30 days')),
            'end'     => date('Y-m-d', strtotime('+1 day')),
        );

        $params = array_merge($default_filters, $filters);
        return WIW_API_Client::request($endpoint, $params, WIW_API_Client::METHOD_GET);
    }

    private function fetch_employees_data() {
        $endpoint = 'users';

        $params = array(
            'include' => 'positions,sites',
            'employment_status' => 1
        );

        return WIW_API_Client::request($endpoint, $params, WIW_API_Client::METHOD_GET);
    }

    private function fetch_locations_data() {
        $endpoint = 'sites';
        return WIW_API_Client::request($endpoint, array(), WIW_API_Client::METHOD_GET);
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
                $shifts         = isset($shifts_data->shifts) ? $shifts_data->shifts : array();
                $included_users = isset($shifts_data->users) ? $shifts_data->users : array();
                $included_sites = isset($shifts_data->sites) ? $shifts_data->sites : array();

                $user_map = array_column($included_users, null, 'id');
                $site_map = array_column($included_sites, null, 'id');
                $site_map[0] = (object) array('name' => 'No Assigned Location');

                foreach ($shifts as &$shift_entry) {
                    $calculated_duration = $this->calculate_shift_duration_in_hours($shift_entry);
                    $shift_entry->calculated_duration = $calculated_duration;
                }
                unset($shift_entry);

                $shifts = $this->sort_timesheet_data( $shifts, $user_map );
                $grouped_shifts = $this->group_timesheet_by_pay_period( $shifts, $user_map );

                $wp_timezone_string = get_option('timezone_string');
                if (empty($wp_timezone_string)) { $wp_timezone_string = 'UTC'; }
                $wp_timezone = new DateTimeZone($wp_timezone_string);
                $time_format = get_option('time_format') ?: 'g:i A';

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
                            ?>
                            <tr class="wiw-employee-header">
                                <td colspan="9" style="background-color: #e6e6fa; font-weight: bold; font-size: 1.1em;">
                                    👤 Employee: <?php echo esc_html($employee_name); ?>
                                </td>
                            </tr>
                            <?php

                            foreach ($periods as $period_start_date => $period_data) :
                                $period_end_date = date('Y-m-d', strtotime($period_start_date . ' + 13 days'));
                                $total_scheduled_hours = number_format($period_data['total_clocked_hours'] ?? 0.0, 2);
                                ?>
                                <tr class="wiw-period-total">
                                    <td colspan="7" style="background-color: #f0f0ff; font-weight: bold;">
                                        📅 Pay Period: <?php echo esc_html($period_start_date); ?> to <?php echo esc_html($period_end_date); ?>
                                    </td>
                                    <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo $total_scheduled_hours; ?></td>
                                    <td style="background-color: #f0f0ff;"></td>
                                </tr>
                                <?php

                                foreach ($period_data['records'] as $shift_entry) :
                                    $shift_id = $shift_entry->id ?? 'N/A';
                                    $site_lookup_id = $shift_entry->site_id ?? 0;

                                    $site_obj = $site_map[$site_lookup_id] ?? null;
                                    $location_name = ($site_obj && isset($site_obj->name)) ? esc_html($site_obj->name) : 'No Assigned Location';

                                    $start_time_utc = $shift_entry->start_time ?? '';
                                    $end_time_utc   = $shift_entry->end_time ?? '';
                                    $break_minutes  = $shift_entry->break ?? 0;

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

                                    $duration = number_format($shift_entry->calculated_duration ?? 0.0, 2);
                                    $row_id = 'wiw-shift-raw-' . $global_row_index++;
                                    $date_cell_style = ($date_match) ? '' : 'style="background-color: #ffe0e0;" title="Shift ends on a different day."';
                                    ?>
                                    <tr class="wiw-daily-record">
                                        <td><?php echo esc_html($shift_id); ?></td>
                                        <td <?php echo $date_cell_style; ?>><?php echo esc_html($display_date); ?></td>
                                        <td><?php echo esc_html($employee_name); ?></td>
                                        <td><?php echo esc_html($location_name); ?></td>
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

                                    <!-- ✅ FIXED: Properly close TR (was </div>) -->
                                    <tr id="<?php echo esc_attr($row_id); ?>" style="display:none; background-color: #f9f9f9;">
                                        <td colspan="9">
                                            <div style="padding: 10px; border: 1px solid #ccc; max-height: 300px; overflow: auto;">
                                                <strong>Raw API Data:</strong>
                                                <pre style="font-size: 11px;"><?php print_r($shift_entry); ?></pre>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                endforeach;
                            endforeach;
                        endforeach;
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

                <?php endif; ?>
                <?php
            }
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
                $users = isset($employees_data->users) ? $employees_data->users : array();

                $position_name_map = array(
                    2611462 => 'ECA',
                    2611465 => 'RECE',
                );

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
                            <th width="15%">Positions</th>
                            <th width="15%">Employee Code</th>
                            <th width="12%">Status</th>
                            <th width="15%">View Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $employee_row_index = 0;
                        foreach ($users as $user) :
                            $user_id = $user->id ?? 'N/A';
                            $full_name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

                            $employee_code = $user->employee_code ?? 'N/A';

                            $user_position_ids = $user->positions ?? array();
                            $mapped_positions = array();

                            foreach ($user_position_ids as $pos_id) {
                                if (isset($position_name_map[$pos_id])) {
                                    $mapped_positions[] = $position_name_map[$pos_id];
                                }
                            }

                            $positions_display = !empty($mapped_positions) ? implode(', ', $mapped_positions) : 'N/A';
                            $status = ($user->is_active ?? false) ? 'Active' : 'Inactive';

                            $row_id = 'wiw-employee-raw-' . $employee_row_index++;
                            ?>
                            <tr class="wiw-employee-record">
                                <td><?php echo esc_html($user_id); ?></td>
                                <td style="font-weight: bold;"><?php echo esc_html($full_name); ?></td>
                                <td><?php echo esc_html($user->email ?? 'N/A'); ?></td>
                                <td><?php echo esc_html($positions_display); ?></td>
                                <td><?php echo esc_html($employee_code); ?></td>
                                <td><?php echo esc_html($status); ?></td>
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

                <?php endif;
            }
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
                $sites = isset($locations_data->sites) ? $locations_data->sites : array();

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
                            <th width="20%">View Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $location_row_index = 0;
                        foreach ($sites as $site) :
                            $site_id = $site->id ?? 'N/A';
                            $site_name = $site->name ?? 'N/A';

                            $site_address = trim(
                                ($site->address ?? '') .
                                (!empty($site->address) && !empty($site->city) ? ', ' : '') .
                                ($site->city ?? '') .
                                (!empty($site->city) && !empty($site->zip_code) ? ' ' : '') .
                                ($site->zip_code ?? '')
                            );

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
                        if (typeof window.wiwToggleRawListeners === 'undefined') {
                            $('.action-toggle-raw').on('click', function() {
                                var targetId = $(this).data('target');
                                $('#' + targetId).toggle();
                            });
                            window.wiwToggleRawListeners = true;
                        }
                    });
                </script>

                <?php endif;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Renders the Local Timesheets view (Admin Area) from the local DB tables.
     */
    public function admin_local_timesheets_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wiw-timesheets' ) );
        }

        global $wpdb;

        $table_timesheets        = $wpdb->prefix . 'wiw_timesheets';
        $table_timesheet_entries = $wpdb->prefix . 'wiw_timesheet_entries';

        $selected_id = isset( $_GET['timesheet_id'] ) ? (int) $_GET['timesheet_id'] : 0;
        
        
        require_once WIW_PLUGIN_PATH . 'admin/local-timesheets-page.php';

    }

/**
 * Admin-post handler: Reset a local timesheet back to the original API data.
 * Deletes local edits and re-syncs the untouched data from When I Work.
 */
public function handle_reset_local_timesheet() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied.' );
    }

    if (
        ! isset( $_POST['wiw_reset_nonce'] ) ||
        ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['wiw_reset_nonce'] ) ),
            'wiw_reset_local_timesheet'
        )
    ) {
        wp_die( 'Security check failed.' );
    }

    global $wpdb;

    $timesheet_id = isset( $_POST['timesheet_id'] ) ? absint( $_POST['timesheet_id'] ) : 0;

    $redirect_base = admin_url( 'admin.php?page=wiw-local-timesheets' );
    $redirect_back = add_query_arg(
        array( 'timesheet_id' => $timesheet_id ),
        $redirect_base
    );

    if ( ! $timesheet_id ) {
        wp_safe_redirect( add_query_arg( 'reset_error', rawurlencode( 'Invalid timesheet ID.' ), $redirect_base ) );
        exit;
    }

$table_headers = $wpdb->prefix . 'wiw_timesheets';
$table_entries = $wpdb->prefix . 'wiw_timesheet_entries';
$table_logs    = $wpdb->prefix . 'wiw_timesheet_edit_logs';
$table_flags   = $wpdb->prefix . 'wiw_timesheet_flags';

    $header = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table_headers} WHERE id = %d", $timesheet_id )
    );

    if ( ! $header ) {
        wp_safe_redirect( add_query_arg( 'reset_error', rawurlencode( 'Timesheet not found.' ), $redirect_base ) );
        exit;
    }

    // WordPress timezone
    $tz_string = get_option( 'timezone_string' );
    if ( empty( $tz_string ) ) { $tz_string = 'UTC'; }
    $wp_timezone = new DateTimeZone( $tz_string );

    // Helper: compare minute precision to avoid "seconds-only" diffs
    $to_minute = function( $datetime ) {
        $datetime = is_string( $datetime ) ? trim( $datetime ) : '';
        if ( $datetime === '' ) return '';
        return ( strlen( $datetime ) >= 16 ) ? substr( $datetime, 0, 16 ) : $datetime;
    };

    // 1) Snapshot BEFORE reset
    $before_entries = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, wiw_time_id, clock_in, clock_out, break_minutes
             FROM {$table_entries}
             WHERE timesheet_id = %d",
            $timesheet_id
        )
    );

    $before_map = array();
    foreach ( $before_entries as $row ) {
        $wiw_id = (int) ( $row->wiw_time_id ?? 0 );
        if ( ! $wiw_id ) { continue; }

        $before_map[ $wiw_id ] = array(
            'clock_in'      => (string) ( $row->clock_in ?? '' ),
            'clock_out'     => (string) ( $row->clock_out ?? '' ),
            'break_minutes' => (int) ( $row->break_minutes ?? 0 ),
        );
    }

    // 2) Fetch fresh data from API for the header's week + employee + location
$start = (string) $header->week_start_date;
$end   = ! empty( $header->week_end_date )
    ? date( 'Y-m-d', strtotime( $header->week_end_date . ' +1 day' ) )
    : date( 'Y-m-d', strtotime( $header->week_start_date . ' +14 days' ) );


    $api = WIW_API_Client::request(
        'times',
        array(
            'include' => 'users,shifts,sites',
            'start'   => $start,
            'end'     => $end,
        ),
        WIW_API_Client::METHOD_GET
    );

    if ( is_wp_error( $api ) ) {
        wp_safe_redirect( add_query_arg( 'reset_error', rawurlencode( $api->get_error_message() ), $redirect_back ) );
        exit;
    }

    $times  = isset( $api->times )  ? $api->times  : array();
    $users  = isset( $api->users )  ? $api->users  : array();
    $shifts = isset( $api->shifts ) ? $api->shifts : array();

    $user_map  = array_column( $users,  null, 'id' );
    $shift_map = array_column( $shifts, null, 'id' );

    // 3) Build reset map (API truth) for ONLY this header (employee + location + week)
    $reset_map      = array();
    $filtered_times = array();

    foreach ( $times as $time_entry ) {
        $employee_id = (int) ( $time_entry->user_id ?? 0 );
        if ( $employee_id !== (int) $header->employee_id ) {
            continue;
        }

        $shift_id = (int) ( $time_entry->shift_id ?? 0 );
        $shift    = $shift_id ? ( $shift_map[ $shift_id ] ?? null ) : null;
        $site_id  = $shift ? (int) ( $shift->site_id ?? 0 ) : 0;

        if ( $site_id !== (int) $header->location_id ) {
            continue;
        }

        $start_time_utc = (string) ( $time_entry->start_time ?? '' );
        if ( $start_time_utc === '' ) {
            continue;
        }

// Verify pay period start matches using the same BIWEEKLY logic as sync (Sunday-anchored)
try {
    $dt_week = new DateTime( $start_time_utc, new DateTimeZone( 'UTC' ) );
    $dt_week->setTimezone( $wp_timezone );

    // Anchor: 2025-12-07 is a known pay period start (Sunday).
    $anchor = new DateTime( '2025-12-07 00:00:00', $wp_timezone );

    // 1) Move dt_week back to the Sunday of its week.
    $dayN = (int) $dt_week->format( 'N' ); // 1=Mon..7=Sun
    $days_back_to_sunday = ( $dayN % 7 );  // Sun(7)->0, Mon(1)->1, ...
    if ( $days_back_to_sunday !== 0 ) {
        $dt_week->modify( "-{$days_back_to_sunday} days" );
    }

    // 2) Snap that Sunday to the correct biweekly boundary relative to the anchor.
    $diff_days = (int) floor( ( $dt_week->getTimestamp() - $anchor->getTimestamp() ) / DAY_IN_SECONDS );
    $mod = $diff_days % 14;
    if ( $mod < 0 ) { $mod += 14; }
    if ( $mod !== 0 ) {
        $dt_week->modify( '-' . $mod . ' days' );
    }

    if ( $dt_week->format( 'Y-m-d' ) !== (string) $header->week_start_date ) {
        continue;
    }
} catch ( Exception $e ) {
    continue;
}

        $wiw_time_id = (int) ( $time_entry->id ?? 0 );
        if ( ! $wiw_time_id ) { continue; }

        // Convert API UTC timestamps to local WP time for logging + (optionally) storage
        try {
            $dt_in = new DateTime( $start_time_utc, new DateTimeZone( 'UTC' ) );
            $dt_in->setTimezone( $wp_timezone );
            $clock_in_local = $dt_in->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            continue;
        }

        $clock_out_local = '';
        $end_time_utc = (string) ( $time_entry->end_time ?? '' );
        if ( $end_time_utc !== '' ) {
            try {
                $dt_out = new DateTime( $end_time_utc, new DateTimeZone( 'UTC' ) );
                $dt_out->setTimezone( $wp_timezone );
                $clock_out_local = $dt_out->format( 'Y-m-d H:i:s' );
            } catch ( Exception $e ) {
                $clock_out_local = '';
            }
        }

        $break_minutes = (int) ( $time_entry->break ?? 0 );

        $reset_map[ $wiw_time_id ] = array(
            'clock_in'      => $clock_in_local,
            'clock_out'     => $clock_out_local,
            'break_minutes' => $break_minutes,
        );

        $filtered_times[] = $time_entry;
    }

    // If we found nothing to restore, do NOT delete anything.
    if ( empty( $filtered_times ) ) {
        wp_safe_redirect(
            add_query_arg(
                'reset_error',
                rawurlencode( 'No matching API entries found to restore for this timesheet.' ),
                $redirect_back
            )
        );
        exit;
    }

    // 4) Log diffs with (Reset)
    $current_user = wp_get_current_user();
    $now          = current_time( 'mysql' );

    foreach ( $before_map as $wiw_time_id => $before ) {
        if ( ! isset( $reset_map[ $wiw_time_id ] ) ) {
            continue;
        }

        $after = $reset_map[ $wiw_time_id ];

        $changes = array(
            'Clock in (Reset)'   => array( $to_minute( (string) ( $before['clock_in'] ?? '' ) ),  $to_minute( (string) ( $after['clock_in'] ?? '' ) ) ),
            'Clock out (Reset)'  => array( $to_minute( (string) ( $before['clock_out'] ?? '' ) ), $to_minute( (string) ( $after['clock_out'] ?? '' ) ) ),
            'Break Mins (Reset)' => array( (string) (int) ( $before['break_minutes'] ?? 0 ),      (string) (int) ( $after['break_minutes'] ?? 0 ) ),
        );

        foreach ( $changes as $edit_type => $pair ) {
            $old_val = (string) ( $pair[0] ?? '' );
            $new_val = (string) ( $pair[1] ?? '' );

            if ( $old_val === $new_val ) {
                continue;
            }

            $wpdb->insert(
                $table_logs,
                array(
                    'timesheet_id'           => (int) $timesheet_id,
                    'entry_id'               => 0,
                    'wiw_time_id'            => (int) $wiw_time_id,
                    'edit_type'              => (string) $edit_type,
                    'old_value'              => $old_val,
                    'new_value'              => $new_val,
                    'edited_by_user_id'      => (int) ( $current_user->ID ?? 0 ),
                    'edited_by_user_login'   => (string) ( $current_user->user_login ?? '' ),
                    'edited_by_display_name' => (string) ( $current_user->display_name ?? '' ),
                    'employee_id'            => (int) ( $header->employee_id ?? 0 ),
                    'employee_name'          => (string) ( $header->employee_name ?? '' ),
                    'location_id'            => (int) ( $header->location_id ?? 0 ),
                    'location_name'          => (string) ( $header->location_name ?? '' ),
                    'week_start_date'        => (string) ( $header->week_start_date ?? '' ),
                    'created_at'             => $now,
                )
            );
        }
    }

    // PREPROCESS filtered times so sync groups them into THIS header (employee + week + location)
    foreach ( $filtered_times as &$time_entry ) {
        // Force location fields to match this header key
        $time_entry->location_id   = (int) ( $header->location_id ?? 0 );
        $time_entry->location_name = (string) ( $header->location_name ?? '' );

        // Convert start/end to local strings so sync writes correct DATETIME values
        if ( ! empty( $time_entry->start_time ) ) {
            try {
                $dt_local_in = new DateTime( (string) $time_entry->start_time, new DateTimeZone( 'UTC' ) );
                $dt_local_in->setTimezone( $wp_timezone );
                $time_entry->start_time = $dt_local_in->format( 'Y-m-d H:i:s' );
            } catch ( Exception $e ) {}
        }

        if ( ! empty( $time_entry->end_time ) ) {
            try {
                $dt_local_out = new DateTime( (string) $time_entry->end_time, new DateTimeZone( 'UTC' ) );
                $dt_local_out->setTimezone( $wp_timezone );
                $time_entry->end_time = $dt_local_out->format( 'Y-m-d H:i:s' );
            } catch ( Exception $e ) {}
        }

        // Durations needed by sync
        $time_entry->calculated_duration = $this->calculate_timesheet_duration_in_hours( $time_entry );

        $shift_id = (int) ( $time_entry->shift_id ?? 0 );
        $shift    = $shift_id ? ( $shift_map[ $shift_id ] ?? null ) : null;

        if ( $shift ) {
            $time_entry->scheduled_duration = $this->calculate_shift_duration_in_hours( $shift );
        } else {
            $time_entry->scheduled_duration = 0.0;
        }
    }
    unset( $time_entry );

    // 5) Purge flags for any WIW time IDs we are about to restore (prevents stale resolved/active flags)
$purge_ids = array_unique( array_merge( array_keys( (array) $before_map ), array_keys( (array) $reset_map ) ) );
$purge_ids = array_values( array_filter( array_map( 'absint', (array) $purge_ids ) ) );

if ( ! empty( $purge_ids ) ) {
    $in = implode( ',', $purge_ids );
    $wpdb->query( "DELETE FROM {$table_flags} WHERE wiw_time_id IN ({$in})" );
}

// 6) Delete entries + reset header totals/status
    $wpdb->delete( $table_entries, array( 'timesheet_id' => $timesheet_id ), array( '%d' ) );

    $wpdb->update(
        $table_headers,
        array(
            'total_scheduled_hours' => 0.00,
            'total_clocked_hours'   => 0.00,
            'status'                => 'pending',
            'updated_at'            => $now,
        ),
        array( 'id' => $timesheet_id ),
        array( '%f', '%f', '%s', '%s' ),
        array( '%d' )
    );

// 6) Re-sync ONLY this header’s filtered times (pass shift_map so scheduled_start/end populate)
$this->sync_timesheets_to_local_db( $filtered_times, $user_map, $wp_timezone, $shift_map );

    wp_safe_redirect( add_query_arg( 'reset_success', '1', $redirect_back ) );
    exit;
}

public function ajax_local_update_entry() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
    }

    check_ajax_referer( 'wiw_local_edit_entry', 'security' );

    global $wpdb;

    $entry_id       = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
    $break_minutes  = isset( $_POST['break_minutes'] ) ? absint( $_POST['break_minutes'] ) : null;
    $clock_in_time  = isset( $_POST['clock_in_time'] ) ? sanitize_text_field( wp_unslash( $_POST['clock_in_time'] ) ) : '';
    $clock_out_time = isset( $_POST['clock_out_time'] ) ? sanitize_text_field( wp_unslash( $_POST['clock_out_time'] ) ) : '';

    if ( ! $entry_id || empty( $clock_in_time ) || empty( $clock_out_time ) ) {
        wp_send_json_error( array( 'message' => 'Missing or invalid parameters.' ) );
    }

    if ( ! preg_match( '/^\d{2}:\d{2}$/', $clock_in_time ) || ! preg_match( '/^\d{2}:\d{2}$/', $clock_out_time ) ) {
        wp_send_json_error( array( 'message' => 'Time must be in HH:MM format.' ) );
    }

    $table_entries = $wpdb->prefix . 'wiw_timesheet_entries';
    $table_headers = $wpdb->prefix . 'wiw_timesheets';

    $entry = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table_entries} WHERE id = %d",
            $entry_id
        )
    );

    if ( ! $entry ) {
        wp_send_json_error( array( 'message' => 'Entry not found.' ) );
    }

    $date         = (string) $entry->date;
    $timesheet_id = (int) $entry->timesheet_id;

    if ( $break_minutes === null ) {
        $break_minutes = (int) $entry->break_minutes;
    }

    $header = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table_headers} WHERE id = %d",
            $timesheet_id
        )
    );

    if ( ! $header ) {
        wp_send_json_error( array( 'message' => 'Timesheet header not found.' ) );
    }

    if ( strtolower( (string) ( $header->status ?? '' ) ) === 'approved' ) {
        wp_send_json_error( array( 'message' => 'This timesheet has been finalized. Changes are not allowed.' ), 403 );
    }

    $employee_name   = (string) $header->employee_name;
    $location_id     = (int)    $header->location_id;
    $location_name   = (string) $header->location_name;
    $week_start_date = (string) $header->week_start_date;

    $current_user    = wp_get_current_user();
    $edited_by_login = (string) ( $current_user->user_login ?? '' );

    $tz_string = get_option( 'timezone_string' );
    if ( empty( $tz_string ) ) {
        $tz_string = 'UTC';
    }
    $tz = new DateTimeZone( $tz_string );

    try {
        $dt_in  = new DateTime( $date . ' ' . $clock_in_time . ':00', $tz );
        $dt_out = new DateTime( $date . ' ' . $clock_out_time . ':00', $tz );
    } catch ( Exception $e ) {
        wp_send_json_error( array( 'message' => 'Error parsing times: ' . $e->getMessage() ) );
    }

    if ( $dt_out <= $dt_in ) {
        wp_send_json_error( array( 'message' => 'Clock Out must be after Clock In.' ) );
    }

    $interval = $dt_in->diff( $dt_out );
    $seconds  = ( $interval->days * 86400 ) + ( $interval->h * 3600 ) + ( $interval->i * 60 ) + $interval->s;

    $seconds -= ( (int) $break_minutes * 60 );
    if ( $seconds < 0 ) {
        $seconds = 0;
    }

$clocked_hours = round( $seconds / 3600, 2 );

// ✅ NEW: Payable hours clamp to scheduled window (when present)
$payable_hours = $clocked_hours;

try {
    // Scheduled bounds are stored as local DATETIME strings (or NULL)
    $sched_start_raw = ! empty( $entry->scheduled_start ) ? (string) $entry->scheduled_start : '';
    $sched_end_raw   = ! empty( $entry->scheduled_end )   ? (string) $entry->scheduled_end   : '';

    if ( $sched_start_raw !== '' || $sched_end_raw !== '' ) {
        $pay_in  = clone $dt_in;
        $pay_out = clone $dt_out;

        if ( $sched_start_raw !== '' ) {
            $dt_sched_start = new DateTime( $sched_start_raw, $tz );
            if ( $pay_in < $dt_sched_start ) {
                $pay_in = $dt_sched_start;
            }
        }

        if ( $sched_end_raw !== '' ) {
            $dt_sched_end = new DateTime( $sched_end_raw, $tz );
            if ( $pay_out > $dt_sched_end ) {
                $pay_out = $dt_sched_end;
            }
        }

        if ( $pay_out <= $pay_in ) {
            $payable_hours = 0.0;
        } else {
            $pint = $pay_in->diff( $pay_out );
            $psec = ( $pint->days * 86400 ) + ( $pint->h * 3600 ) + ( $pint->i * 60 ) + $pint->s;

            $psec -= ( (int) $break_minutes * 60 );
            if ( $psec < 0 ) {
                $psec = 0;
            }

            $payable_hours = round( $psec / 3600, 2 );
        }
    }
} catch ( Exception $e ) {
    // If anything goes wrong, fall back to clocked (safe + predictable)
    $payable_hours = $clocked_hours;
}


    $clock_in_str  = $dt_in->format( 'Y-m-d H:i:s' );
    $clock_out_str = $dt_out->format( 'Y-m-d H:i:s' );
    $now           = current_time( 'mysql' );

    // Display format (no seconds) for the UI
    $clock_in_display  = $dt_in->format( 'g:ia' );
    $clock_out_display = $dt_out->format( 'g:ia' );

    // --- LOGGING (unchanged) ---
    $old_clock_in_raw  = (string) ( $entry->clock_in  ? $entry->clock_in  : '' );
    $old_clock_out_raw = (string) ( $entry->clock_out ? $entry->clock_out : '' );

    $old_clock_in_norm  = $this->normalize_datetime_to_minute( $old_clock_in_raw );
    $old_clock_out_norm = $this->normalize_datetime_to_minute( $old_clock_out_raw );

    $new_clock_in_norm  = $this->normalize_datetime_to_minute( $clock_in_str );
    $new_clock_out_norm = $this->normalize_datetime_to_minute( $clock_out_str );

    $old_break = (int) $entry->break_minutes;
    $new_break = (int) $break_minutes;

    if ( $old_clock_in_norm !== $new_clock_in_norm ) {
        $this->insert_local_edit_log( array(
            'timesheet_id'           => $timesheet_id,
            'entry_id'               => $entry_id,
            'wiw_time_id'            => (int) $entry->wiw_time_id,
            'edit_type'              => 'Clock in',
            'old_value'              => $old_clock_in_norm,
            'new_value'              => $new_clock_in_norm,
            'edited_by_user_id'      => get_current_user_id(),
            'edited_by_user_login'   => $edited_by_login,
            'edited_by_display_name' => (string) ( $current_user->display_name ?? '' ),
            'employee_id'            => (int) $entry->user_id,
            'employee_name'          => $employee_name,
            'location_id'            => $location_id,
            'location_name'          => $location_name,
            'week_start_date'        => $week_start_date,
            'created_at'             => $now,
        ) );
    }

    if ( $old_clock_out_norm !== $new_clock_out_norm ) {
        $this->insert_local_edit_log( array(
            'timesheet_id'           => $timesheet_id,
            'entry_id'               => $entry_id,
            'wiw_time_id'            => (int) $entry->wiw_time_id,
            'edit_type'              => 'Clock out',
            'old_value'              => $old_clock_out_norm,
            'new_value'              => $new_clock_out_norm,
            'edited_by_user_id'      => get_current_user_id(),
            'edited_by_user_login'   => $edited_by_login,
            'edited_by_display_name' => (string) ( $current_user->display_name ?? '' ),
            'created_at'             => $now,
            'employee_id'            => (int) $entry->user_id,
            'employee_name'          => $employee_name,
            'location_id'            => $location_id,
            'location_name'          => $location_name,
            'week_start_date'        => $week_start_date,
        ) );
    }

    if ( $old_break !== $new_break ) {
        $this->insert_local_edit_log( array(
            'timesheet_id'           => $timesheet_id,
            'entry_id'               => $entry_id,
            'wiw_time_id'            => (int) $entry->wiw_time_id,
            'edit_type'              => 'Break Mins',
            'old_value'              => (string) $old_break,
            'new_value'              => (string) $new_break,
            'edited_by_user_id'      => get_current_user_id(),
            'edited_by_user_login'   => $edited_by_login,
            'edited_by_display_name' => (string) ( $current_user->display_name ?? '' ),
            'created_at'             => $now,
            'employee_id'            => (int) $entry->user_id,
            'employee_name'          => $employee_name,
            'location_id'            => $location_id,
            'location_name'          => $location_name,
            'week_start_date'        => $week_start_date,
        ) );
    }

$updated = $wpdb->update(
    $table_entries,
    array(
        'clock_in'       => $clock_in_str,
        'clock_out'      => $clock_out_str,
        'break_minutes'  => (int) $break_minutes,
        'clocked_hours'  => $clocked_hours,
        'payable_hours'  => (float) $payable_hours,
        'updated_at'     => $now,
    ),
    array( 'id' => $entry_id ),
    array( '%s', '%s', '%d', '%f', '%f', '%s' ),
    array( '%d' )
);

// ✅ IMPORTANT: Recalculate flags after any local edit so flags resolve/reactivate correctly
try {
    $sched_start_local = ! empty( $entry->scheduled_start ) ? (string) $entry->scheduled_start : '';
    $sched_end_local   = ! empty( $entry->scheduled_end )   ? (string) $entry->scheduled_end   : '';

    $this->wiwts_sync_store_time_flags(
        (int) $entry->wiw_time_id,
        (string) $clock_in_str,
        (string) $clock_out_str,
        (string) $sched_start_local,
        (string) $sched_end_local,
        $tz
    );
} catch ( Exception $e ) {
    // Do not fail the edit if flags calculation fails
}

    if ( false === $updated ) {
        wp_send_json_error( array( 'message' => 'Database update failed for entry.' ) );
    }

    $total_clocked = (float) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(clocked_hours) FROM {$table_entries} WHERE timesheet_id = %d",
            $timesheet_id
        )
    );

    $wpdb->update(
        $table_headers,
        array(
            'total_clocked_hours' => $total_clocked,
            'updated_at'          => $now,
        ),
        array( 'id' => $timesheet_id ),
        array( '%f', '%s' ),
        array( '%d' )
    );

wp_send_json_success(
    array(
        // IMPORTANT: these are now the formatted strings for display
        'clock_in_display'             => $clock_in_display,
        'clock_out_display'            => $clock_out_display,
        'break_minutes_display'        => (string) (int) $break_minutes,
        'clocked_hours_display'        => number_format( $clocked_hours, 2 ),
        'payable_hours_display'        => number_format( (float) $payable_hours, 2 ),
        'header_total_clocked_display' => number_format( $total_clocked, 2 ),
    )
);
}

public function ajax_local_approve_entry() {
    // Capability
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
    }

    // Nonce
    check_ajax_referer( 'wiw_local_approve_entry', 'security' );

    global $wpdb;

    $entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
    if ( ! $entry_id ) {
        wp_send_json_error( array( 'message' => 'Invalid entry ID.' ), 400 );
    }

    $table_entries = $wpdb->prefix . 'wiw_timesheet_entries';
    $table_headers = $wpdb->prefix . 'wiw_timesheets';

    // Load entry
    $entry = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table_entries} WHERE id = %d",
            $entry_id
        )
    );

    if ( ! $entry ) {
        wp_send_json_error( array( 'message' => 'Entry not found.' ), 404 );
    }

    $timesheet_id = (int) ( $entry->timesheet_id ?? 0 );
    if ( ! $timesheet_id ) {
        wp_send_json_error( array( 'message' => 'Timesheet ID missing on entry.' ), 400 );
    }

    // Load header
    $header = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table_headers} WHERE id = %d",
            $timesheet_id
        )
    );

    if ( ! $header ) {
        wp_send_json_error( array( 'message' => 'Timesheet header not found.' ), 404 );
    }

    // Prevent changes if finalized
    if ( strtolower( (string) ( $header->status ?? '' ) ) === 'approved' ) {
        wp_send_json_error( array( 'message' => 'This timesheet has been finalized. Changes are not allowed.' ), 403 );
    }

    $old_status = strtolower( (string) ( $entry->status ?? 'pending' ) );
    if ( $old_status === 'approved' ) {
        wp_send_json_success( array( 'message' => 'Entry already approved.' ) );
    }

    $now = current_time( 'mysql' );

    // Approve the entry
    $updated = $wpdb->update(
        $table_entries,
        array(
            'status'     => 'approved',
            'updated_at' => $now,
        ),
        array( 'id' => $entry_id ),
        array( '%s', '%s' ),
        array( '%d' )
    );

    if ( false === $updated ) {
        wp_send_json_error( array( 'message' => 'Failed to approve entry in database.' ), 500 );
    }

    // Log the approval (optional but recommended for audit trail)
    try {
        $current_user = wp_get_current_user();

        $this->insert_local_edit_log( array(
            'timesheet_id'           => (int) $timesheet_id,
            'entry_id'               => (int) $entry_id,
            'wiw_time_id'            => (int) ( $entry->wiw_time_id ?? 0 ),
            'edit_type'              => 'Approved Time Record',
            'old_value'              => (string) ( $entry->status ?? 'pending' ),
            'new_value'              => 'approved',
            'edited_by_user_id'      => (int) ( $current_user->ID ?? 0 ),
            'edited_by_user_login'   => (string) ( $current_user->user_login ?? '' ),
            'edited_by_display_name' => (string) ( $current_user->display_name ?? '' ),
            'employee_id'            => (int) ( $header->employee_id ?? 0 ),
            'employee_name'          => (string) ( $header->employee_name ?? '' ),
            'location_id'            => (int) ( $header->location_id ?? 0 ),
            'location_name'          => (string) ( $header->location_name ?? '' ),
            'week_start_date'        => (string) ( $header->week_start_date ?? '' ),
            'created_at'             => $now,
        ) );
    } catch ( Exception $e ) {
        // Do not fail approval if logging fails
    }

    wp_send_json_success( array(
        'message' => 'Entry approved.',
    ) );
}

/**
 * Admin-post handler: Finalize (Sign Off) a local timesheet.
 * - Requires all daily entries to be approved
 * - Updates wp_wiw_timesheets.status from pending -> approved
 * - Writes a log entry using the TIMESHEET ID stored into the wiw_time_id column
 * - Prevents Reset from API and any further edits/approvals
 */
public function handle_finalize_local_timesheet() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied.' );
    }

    if (
        ! isset( $_POST['wiw_finalize_nonce'] ) ||
        ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['wiw_finalize_nonce'] ) ),
            'wiw_finalize_local_timesheet'
        )
    ) {
        wp_die( 'Security check failed.' );
    }

    global $wpdb;

    $timesheet_id = isset( $_POST['timesheet_id'] ) ? absint( $_POST['timesheet_id'] ) : 0;

    $redirect_base = admin_url( 'admin.php?page=wiw-local-timesheets' );
    $redirect_back = add_query_arg( array( 'timesheet_id' => $timesheet_id ), $redirect_base );

    if ( ! $timesheet_id ) {
        wp_safe_redirect( add_query_arg( 'finalize_error', rawurlencode( 'Invalid timesheet ID.' ), $redirect_base ) );
        exit;
    }

    $table_headers = $wpdb->prefix . 'wiw_timesheets';
    $table_entries = $wpdb->prefix . 'wiw_timesheet_entries';

    $header = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table_headers} WHERE id = %d", $timesheet_id )
    );

    if ( ! $header ) {
        wp_safe_redirect( add_query_arg( 'finalize_error', rawurlencode( 'Timesheet not found.' ), $redirect_base ) );
        exit;
    }

    $current_status = strtolower( (string) ( $header->status ?? 'pending' ) );
    if ( $current_status === 'approved' ) {
        wp_safe_redirect( add_query_arg( 'finalize_success', '1', $redirect_back ) );
        exit;
    }

    // Must have entries, and ALL must be approved
    $statuses = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT status
             FROM {$table_entries}
             WHERE timesheet_id = %d",
            $timesheet_id
        )
    );

    if ( empty( $statuses ) ) {
        wp_safe_redirect( add_query_arg( 'finalize_error', rawurlencode( 'No daily records found for this timesheet.' ), $redirect_back ) );
        exit;
    }

    foreach ( $statuses as $st ) {
        if ( strtolower( (string) $st ) !== 'approved' ) {
            wp_safe_redirect( add_query_arg( 'finalize_error', rawurlencode( 'All daily time records must be approved before sign off.' ), $redirect_back ) );
            exit;
        }
    }

    $now = current_time( 'mysql' );

    $updated = $wpdb->update(
        $table_headers,
        array(
            'status'     => 'approved',
            'updated_at' => $now,
        ),
        array( 'id' => $timesheet_id ),
        array( '%s', '%s' ),
        array( '%d' )
    );

    if ( false === $updated ) {
        wp_safe_redirect( add_query_arg( 'finalize_error', rawurlencode( 'Failed to finalize timesheet in database.' ), $redirect_back ) );
        exit;
    }

    // Log finalize action
    $current_user = wp_get_current_user();

    // IMPORTANT: store the TIMESHEET ID in the wiw_time_id column for this log row
    $this->insert_local_edit_log( array(
        'timesheet_id'           => (int) $timesheet_id,
        'entry_id'               => 0,
        'wiw_time_id'            => (int) $timesheet_id,
        'edit_type'              => 'Approved Time Sheet',
        'old_value'              => (string) ( $header->status ?? 'pending' ),
        'new_value'              => 'approved',
        'edited_by_user_id'      => (int) ( $current_user->ID ?? 0 ),
        'edited_by_user_login'   => (string) ( $current_user->user_login ?? '' ),
        'edited_by_display_name' => (string) ( $current_user->display_name ?? '' ),
        'employee_id'            => (int) ( $header->employee_id ?? 0 ),
        'employee_name'          => (string) ( $header->employee_name ?? '' ),
        'location_id'            => (int) ( $header->location_id ?? 0 ),
        'location_name'          => (string) ( $header->location_name ?? '' ),
        'week_start_date'        => (string) ( $header->week_start_date ?? '' ),
        'created_at'             => $now,
    ) );

    wp_safe_redirect( add_query_arg( 'finalize_success', '1', $redirect_back ) );
    exit;
}

}

// Instantiate the core class
new WIW_Timesheet_Manager();

/**
 * Run installation routine on plugin activation.
 */
register_activation_hook( __FILE__, 'wiw_timesheet_manager_install' );
