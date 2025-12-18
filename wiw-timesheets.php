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

        // ✅ 7. Register additional AJAX hooks (THIS was missing)
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
                                <tr><th scope="row">Hrs Scheduled</th><td>Total Scheduled Hours. Week of totals aggregate this value.</td></tr>
                                <tr><th scope="row">Clock In / Out</th><td>Actual Clock In/Out Time (local timezone). Clock Out shows Active (N/A) if the shift is open.</td></tr>
                                <tr><th scope="row">Breaks (Min -)</th><td>Time deducted for breaks, derived from the break_hours raw data converted to minutes.</td></tr>
                                <tr><th scope="row">Hrs Clocked</th><td>Total Clocked Hours. Week of totals aggregate this value.</td></tr>
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
                            ?>
                            <tr class="wiw-employee-header">
                                <td colspan="9" style="background-color: #e6e6fa; font-weight: bold; font-size: 1.1em;">
                                    👤 Employee: <?php echo esc_html($employee_name); ?>
                                </td>
                            </tr>
                            <?php

                            foreach ($periods as $period_start_date => $period_data) :
                                $period_end_date = date('Y-m-d', strtotime($period_start_date . ' + 4 days'));
                                $total_scheduled_hours = number_format($period_data['total_clocked_hours'] ?? 0.0, 2);
                                ?>
                                <tr class="wiw-period-total">
                                    <td colspan="7" style="background-color: #f0f0ff; font-weight: bold;">
                                        📅 Week of: <?php echo esc_html($period_start_date); ?> to <?php echo esc_html($period_end_date); ?>
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
        ?>
        <div class="wrap">
            <h1>📁 Local Timesheets (Database View)</h1>
            <p>This page displays timesheets stored locally in WordPress, grouped by Employee, Week, and Location.</p>
        <?php

        if ( $selected_id > 0 ) {
            $header = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_timesheets} WHERE id = %d",
                    $selected_id
                )
            );

            if ( $header ) {
                $list_url = remove_query_arg( 'timesheet_id' );
                ?>
                <p>
                    <a href="<?php echo esc_url( $list_url ); ?>" class="button">← Back to Local Timesheets List</a>
                </p>

                <?php
                if ( isset( $_GET['reset_success'] ) ) : ?>
                    <div class="notice notice-success is-dismissible"><p>✅ Local timesheet reset from When I Work successfully.</p></div>
                <?php endif;

                if ( isset( $_GET['reset_error'] ) && $_GET['reset_error'] !== '' ) : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong>❌ Reset failed:</strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['reset_error'] ) ) ); ?></p>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 10px 0 20px;">
                    <input type="hidden" name="action" value="wiw_reset_local_timesheet" />
                    <input type="hidden" name="timesheet_id" value="<?php echo esc_attr( (int) $header->id ); ?>" />
                    <?php wp_nonce_field( 'wiw_reset_local_timesheet', 'wiw_reset_nonce' ); ?>
                    <button type="submit" class="button button-secondary"
                        onclick="return confirm('Reset will discard ALL local edits for this timesheet and restore the original data from When I Work. Continue?');">
                        Reset from API
                    </button>
                </form>

                <h2>Timesheet #<?php echo esc_html( $header->id ); ?> Details</h2>
                <table class="widefat striped" style="max-width: 900px;">
                    <tbody>
                        <tr>
    <th scope="row" style="width: 200px;">Timesheet ID</th>
    <td>#<?php echo esc_html( $header->id ); ?></td>
</tr>
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
                                Clocked: <span id="wiw-local-header-total-clocked">
                                    <?php echo esc_html( number_format( (float) $header->total_clocked_hours, 2 ) ); ?>
                                </span> hrs
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

                // ✅ Prefetch flags for all entries in one query (avoid N+1 queries)
$table_flags = $wpdb->prefix . 'wiw_timesheet_flags';
$flags_map   = array(); // [wiw_time_id] => array of flag rows

$wiw_ids = array();
foreach ( (array) $entries as $e ) {
    if ( ! empty( $e->wiw_time_id ) ) {
        $wiw_ids[] = (int) $e->wiw_time_id;
    }
}
$wiw_ids = array_values( array_unique( array_filter( $wiw_ids ) ) );

if ( ! empty( $wiw_ids ) ) {
    $in = implode( ',', array_map( 'absint', $wiw_ids ) );

    $flag_rows = $wpdb->get_results(
        "SELECT id, wiw_time_id, flag_type, description, flag_status, created_at, updated_at
         FROM {$table_flags}
         WHERE wiw_time_id IN ({$in})
         ORDER BY wiw_time_id ASC, id ASC"
    );

    foreach ( (array) $flag_rows as $fr ) {
        $tid = (int) ( $fr->wiw_time_id ?? 0 );
        if ( ! $tid ) { continue; }
        if ( ! isset( $flags_map[ $tid ] ) ) {
            $flags_map[ $tid ] = array();
        }
        $flags_map[ $tid ][] = $fr;
    }
}


                if ( empty( $entries ) ) : ?>
                    <p>No entries found for this timesheet.</p>
                <?php else : ?>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th width="10%">Date</th>
                                <th width="12%">Time Record ID</th>
                                <th width="18%">Scheduled Start/End</th>
                                <th width="12%">Clock In</th>
                                <th width="12%">Clock Out</th>
                                <th width="10%">Break (min)</th>
                                <th width="10%">Sched. Hrs</th>
                                <th width="10%">Clocked Hrs</th>
                                <th width="10%">Payable Hrs</th>
                                <th width="6%">Status</th>
                                <th width="6%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $entries as $entry ) : ?>
                            <?php
                            $fmt = function( $dt_string ) {
                                if ( empty( $dt_string ) ) { return 'N/A'; }
                                $tz_string = get_option( 'timezone_string' ) ?: 'UTC';
                                $tz = new DateTimeZone( $tz_string );
                                try {
                                    $dt = new DateTime( (string) $dt_string, $tz );
                                    return $dt->format( 'g:ia' );
                                } catch ( Exception $e ) {
                                    return 'N/A';
                                }
                            };

                            $scheduled_range = 'N/A';
                            if ( ! empty( $entry->scheduled_start ) && ! empty( $entry->scheduled_end ) ) {
                                $scheduled_range = $fmt( $entry->scheduled_start ) . ' to ' . $fmt( $entry->scheduled_end );
                            }

                            $clock_in_display  = $fmt( $entry->clock_in );
                            $clock_out_display = $fmt( $entry->clock_out );

                            $payable_hours_val = isset( $entry->payable_hours ) ? (float) $entry->payable_hours : (float) $entry->clocked_hours;
                            ?>
                            <tr>
                                <td><?php echo esc_html( $entry->date ); ?></td>
                                <td><?php echo esc_html( (int) $entry->wiw_time_id ); ?></td>
                                <td><?php echo esc_html( $scheduled_range ); ?></td>

                                <td class="wiw-local-clock-in"
                                    data-time="<?php echo esc_attr( $entry->clock_in ? substr( (string) $entry->clock_in, 11, 5 ) : '' ); ?>">
                                    <?php echo esc_html( $clock_in_display ); ?>
                                </td>

                                <td class="wiw-local-clock-out"
                                    data-time="<?php echo esc_attr( $entry->clock_out ? substr( (string) $entry->clock_out, 11, 5 ) : '' ); ?>">
                                    <?php echo esc_html( $clock_out_display ); ?>
                                </td>

                                <td class="wiw-local-break-min" data-break="<?php echo esc_attr( (int) $entry->break_minutes ); ?>">
                                    <?php echo esc_html( (int) $entry->break_minutes ); ?>
                                </td>

                                <td><?php echo esc_html( number_format( (float) $entry->scheduled_hours, 2 ) ); ?></td>

                                <td class="wiw-local-clocked-hours">
                                    <?php echo esc_html( number_format( (float) $entry->clocked_hours, 2 ) ); ?>
                                </td>

                                <td class="wiw-local-payable-hours">
                                    <?php echo esc_html( number_format( (float) $payable_hours_val, 2 ) ); ?>
                                </td>

                                <td><?php echo esc_html( $entry->status ); ?></td>

                                <td>
<?php
$wiw_time_id_for_flags = (int) ( $entry->wiw_time_id ?? 0 );
$row_flags            = isset( $flags_map[ $wiw_time_id_for_flags ] ) ? (array) $flags_map[ $wiw_time_id_for_flags ] : array();
$flags_count          = count( $row_flags );
$flags_row_id         = 'wiw-local-flags-' . (int) $entry->id;
?>

<button type="button"
        class="button button-small wiw-local-edit-entry"
        data-entry-id="<?php echo esc_attr( $entry->id ); ?>">
    Edit
</button>

<button type="button"
        class="button button-small wiw-local-toggle-flags"
        data-target="<?php echo esc_attr( $flags_row_id ); ?>"
        <?php echo $flags_count ? '' : 'disabled="disabled"'; ?>>
    Flags<?php echo $flags_count ? ' (' . (int) $flags_count . ')' : ''; ?>
</button>

                                </td>
                            </tr>

                            <tr id="<?php echo esc_attr( $flags_row_id ); ?>" style="display:none; background-color:#f9f9f9;">
                                <td colspan="11">
                                    <div style="padding:10px; border:1px solid #ddd; background:#fff;">
                                        <strong>Flags for Time Record ID:</strong> <?php echo esc_html( (int) $entry->wiw_time_id ); ?>

                                        <?php if ( empty( $row_flags ) ) : ?>
                                            <p style="margin:8px 0 0;">No flags for this entry.</p>
                                        <?php else : ?>
                                            <table class="widefat striped" style="margin-top:10px;">
                                                <thead>
                                                    <tr>
                                                        <th width="10%">Type</th>
                                                        <th>Description</th>
                                                        <th width="12%">Status</th>
                                                        <th width="18%">Created</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ( $row_flags as $fr ) : ?>
                                                        <tr>
                                                            <td><?php echo esc_html( (string) ( $fr->flag_type ?? '' ) ); ?></td>
                                                            <td><?php echo esc_html( (string) ( $fr->description ?? '' ) ); ?></td>
                                                            <td><?php echo esc_html( (string) ( $fr->flag_status ?? '' ) ); ?></td>
                                                            <td><?php echo esc_html( (string) ( $fr->created_at ?? '' ) ); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>

                    <?php
                    $local_edit_nonce = wp_create_nonce( 'wiw_local_edit_entry' );
                    ?>
                    <script type="text/javascript">
                    jQuery(function($) {

                     // Toggle flags row (the hidden <tr> immediately under the entry row)
                        $(document).on('click', '.wiw-local-toggle-flags', function(e) {
                            e.preventDefault();
                            var targetId = $(this).data('target');
                            if (targetId) {
                                $('#' + targetId).toggle();
                            }
                        });

                        // Edit entry button handler
                        $('.wiw-local-edit-entry').on('click', function(e) {
                            e.preventDefault();

                            var $btn    = $(this);
                            var $row    = $btn.closest('tr');
                            var entryId = $btn.data('entry-id');

                            var $cellIn   = $row.find('.wiw-local-clock-in');
                            var $cellOut  = $row.find('.wiw-local-clock-out');
                            var $cellHrs  = $row.find('.wiw-local-clocked-hours');
                            var $cellPay  = $row.find('.wiw-local-payable-hours');

                            var $cellBreak = $row.find('.wiw-local-break-min');
                            var currentBreak = $cellBreak.data('break');
                            if (currentBreak === undefined || currentBreak === null) currentBreak = 0;
                            currentBreak = String(currentBreak);

                            var currentIn  = $cellIn.data('time')  || '';
                            var currentOut = $cellOut.data('time') || '';

                            var newIn = window.prompt('Enter new Clock In time (HH:MM, 24-hour)', currentIn);
                            if (!newIn) return;

                            var newOut = window.prompt('Enter new Clock Out time (HH:MM, 24-hour)', currentOut);
                            if (!newOut) return;

                            var newBreak = window.prompt('Enter Break minutes (0 or more)', currentBreak);
                            if (newBreak === null) return;

                            newBreak = String(newBreak).trim();
                            if (newBreak === '') newBreak = '0';

                            if (!/^\d+$/.test(newBreak)) {
                                alert('Break minutes must be a whole number (e.g., 0, 15, 30).');
                                return;
                            }

                            $.post(ajaxurl, {
                                action: 'wiw_local_update_entry',
                                security: '<?php echo esc_js( $local_edit_nonce ); ?>',
                                entry_id: entryId,
                                clock_in_time: newIn,
                                clock_out_time: newOut,
                                break_minutes: newBreak
                            }).done(function(resp) {
                                if (!resp || !resp.success) {
                                    alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Update failed.');
                                    return;
                                }

                                if (resp.data.clock_in_display) {
                                    $cellIn.text(resp.data.clock_in_display).data('time', newIn);
                                }

                                if (resp.data.clock_out_display) {
                                    $cellOut.text(resp.data.clock_out_display).data('time', newOut);
                                }

                                if (resp.data.break_minutes_display !== undefined) {
                                    $cellBreak
                                        .text(resp.data.break_minutes_display)
                                        .data('break', parseInt(resp.data.break_minutes_display, 10));
                                }

                                if (resp.data.clocked_hours_display) {
                                    $cellHrs.text(resp.data.clocked_hours_display);
                                }

                                if (resp.data.payable_hours_display) {
                                    $cellPay.text(resp.data.payable_hours_display);
                                }

                                if (resp.data.header_total_clocked_display) {
                                    $('#wiw-local-header-total-clocked').text(resp.data.header_total_clocked_display);
                                }

                            }).fail(function() {
                                alert('AJAX error updating entry.');
                            });

                        });
                    });
                    </script>
                <?php endif; ?>

                <?php
            } else {
                ?>
                <div class="notice notice-error">
                    <p>Timesheet not found.</p>
                </div>
                <?php
            }

            echo '</div>'; // .wrap
            return;
        }

        // No specific ID selected: show list of headers (GROUPED like main dashboard)
        $headers = $wpdb->get_results(
            "SELECT * FROM {$table_timesheets}
             ORDER BY employee_name ASC, week_start_date DESC, location_name ASC
             LIMIT 500"
        );

        if ( empty( $headers ) ) : ?>
            <div class="notice notice-warning">
                <p>No local timesheets found. Visit the main WIW Timesheets Dashboard to fetch and sync data.</p>
            </div>
        <?php else :

            $ids = array_map( static function( $r ) { return (int) $r->id; }, $headers );
            $ids = array_filter( $ids );

            $totals_map = array();
            if ( ! empty( $ids ) ) {
                $in = implode( ',', array_map( 'absint', $ids ) );

$rows = $wpdb->get_results(
    "SELECT timesheet_id,
            COALESCE(SUM(break_minutes), 0) AS break_total,
            COALESCE(SUM(payable_hours), 0) AS payable_total,
            MIN(date) AS min_date,

            MIN(scheduled_start) AS min_scheduled_start,
            MAX(scheduled_end)   AS max_scheduled_end,

            MIN(clock_in)  AS min_clock_in,
            MAX(clock_out) AS max_clock_out

     FROM {$table_timesheet_entries}
     WHERE timesheet_id IN ({$in})
     GROUP BY timesheet_id"
);


                foreach ( (array) $rows as $t ) {
                    $tid = (int) ( $t->timesheet_id ?? 0 );
                    if ( ! $tid ) { continue; }
$totals_map[ $tid ] = array(
    'break_total'   => (int) ( $t->break_total ?? 0 ),
    'payable_total' => (float) ( $t->payable_total ?? 0 ),
    'min_date'      => (string) ( $t->min_date ?? '' ),

    'min_scheduled_start' => (string) ( $t->min_scheduled_start ?? '' ),
    'max_scheduled_end'   => (string) ( $t->max_scheduled_end ?? '' ),

    'min_clock_in'        => (string) ( $t->min_clock_in ?? '' ),
    'max_clock_out'       => (string) ( $t->max_clock_out ?? '' ),
);
                }
            }

            $grouped = array();
            foreach ( $headers as $row ) {
                $emp  = (string) ( $row->employee_name ?? 'Unknown' );
                $week = (string) ( $row->week_start_date ?? '' );
                if ( $week === '' ) { continue; }

                if ( ! isset( $grouped[ $emp ] ) ) {
                    $grouped[ $emp ] = array();
                }
                if ( ! isset( $grouped[ $emp ][ $week ] ) ) {
                    $grouped[ $emp ][ $week ] = array(
                        'rows' => array(),
                    );
                }
                $grouped[ $emp ][ $week ]['rows'][] = $row;
            }
            ?>

            <!-- ✅ FIXED: THEAD now has Location column so it matches row output -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
<tr>
    <th width="2%">ID</th>
    <th width="13%">Week of</th>
    <th width="6%">Date</th>

    <th width="11%">Sched. Start/End</th>
    <th width="11%">Clock In/Clock Out</th>

    <th width="12%">Employee</th>
    <th width="16%">Location</th>

    <th width="7%">Break (Min)</th>
    <th width="7%">Sched. Hrs</th>
    <th width="7%">Clocked Hrs</th>
    <th width="7%">Payable Hrs</th>

    <th width="7%">Status</th>
    <th width="8%">Actions</th>
</tr>

                </thead>

                <tbody>
                <?php foreach ( $grouped as $employee_name => $weeks ) : ?>
                    <tr class="wiw-employee-header">
                        <td colspan="13" style="background-color: #e6e6fa; font-weight: bold; font-size: 1.1em;">
                            👤 Employee: <?php echo esc_html( $employee_name ); ?>
                        </td>
                    </tr>

                    <?php foreach ( $weeks as $week_start => $bundle ) :

                        usort( $bundle['rows'], static function( $a, $b ) {
                            $la = (string) ( $a->location_name ?? '' );
                            $lb = (string) ( $b->location_name ?? '' );
                            return strcasecmp( $la, $lb );
                        } );

                        $week_end = '';
                        if ( ! empty( $bundle['rows'][0]->week_end_date ) ) {
                            $week_end = (string) $bundle['rows'][0]->week_end_date;
                        } else {
                            $week_end = date( 'Y-m-d', strtotime( $week_start . ' +4 days' ) );
                        }

                        $week_break   = 0;
                        $week_sched   = 0.0;
                        $week_clocked = 0.0;
                        $week_payable = 0.0;

                        foreach ( $bundle['rows'] as $r ) {
                            $tid = (int) ( $r->id ?? 0 );
                            $week_sched   += (float) ( $r->total_scheduled_hours ?? 0 );
                            $week_clocked += (float) ( $r->total_clocked_hours ?? 0 );
                            $week_break   += (int) ( $totals_map[ $tid ]['break_total'] ?? 0 );
                            $week_payable += (float) ( $totals_map[ $tid ]['payable_total'] ?? 0 );
                        }
                        ?>
                        <tr class="wiw-period-total">
                            <td colspan="7" style="background-color: #f0f0ff; font-weight: bold;">
                                📅 Week of: <?php echo esc_html( $week_start ); ?> to <?php echo esc_html( $week_end ); ?>
                            </td>
                            <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( (int) $week_break ); ?></td>
                            <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( number_format( (float) $week_sched, 2 ) ); ?></td>
                            <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( number_format( (float) $week_clocked, 2 ) ); ?></td>
                            <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( number_format( (float) $week_payable, 2 ) ); ?></td>
                            <td colspan="2" style="background-color: #f0f0ff;"></td>
                        </tr>

                        

                        <?php 
                        
                        $tz_string = get_option( 'timezone_string' ) ?: 'UTC';
$tz        = new DateTimeZone( $tz_string );

$fmt_time = function( $dt_string ) use ( $tz ) {
    if ( empty( $dt_string ) ) return 'N/A';
    try {
        $dt = new DateTime( (string) $dt_string, $tz );
        return $dt->format( 'g:ia' );
    } catch ( Exception $e ) {
        return 'N/A';
    }
};

$fmt_range = function( $start, $end, $separator ) use ( $fmt_time ) {
    if ( empty( $start ) || empty( $end ) ) return 'N/A';
    return $fmt_time( $start ) . $separator . $fmt_time( $end );
};

                        foreach ( $bundle['rows'] as $row ) :
                            $tid = (int) ( $row->id ?? 0 );

                            $break_total   = (int) ( $totals_map[ $tid ]['break_total'] ?? 0 );
                            $payable_total = (float) ( $totals_map[ $tid ]['payable_total'] ?? 0 );

                            $detail_url = add_query_arg(
                                array( 'timesheet_id' => $tid ),
                                menu_page_url( 'wiw-local-timesheets', false )
                            );

                            $min_date = (string) ( $totals_map[ $tid ]['min_date'] ?? '' );
                            if ( $min_date === '' ) { $min_date = '—'; }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $tid ); ?></td>
                                <td>
                                    <?php echo esc_html( $row->week_start_date ); ?>
                                    <?php if ( ! empty( $row->week_end_date ) ) : ?>
                                        &nbsp;to&nbsp;<?php echo esc_html( $row->week_end_date ); ?>
                                    <?php endif; ?>
                                </td>
<td><?php echo esc_html( $min_date ); ?></td>

<?php
$min_sched = (string) ( $totals_map[ $tid ]['min_scheduled_start'] ?? '' );
$max_sched = (string) ( $totals_map[ $tid ]['max_scheduled_end'] ?? '' );
$min_in    = (string) ( $totals_map[ $tid ]['min_clock_in'] ?? '' );
$max_out   = (string) ( $totals_map[ $tid ]['max_clock_out'] ?? '' );

$scheduled_range = $fmt_range( $min_sched, $max_sched, ' to ' );
$clock_range     = $fmt_range( $min_in,    $max_out,   ' / ' );
?>
<td><?php echo esc_html( $scheduled_range ); ?></td>
<td><?php echo esc_html( $clock_range ); ?></td>

<td><?php echo esc_html( $row->employee_name ); ?></td>
<td>
    <?php echo esc_html( $row->location_name ); ?>

                                    <?php if ( ! empty( $row->location_id ) ) : ?>
                                        <br/><small>(ID: <?php echo esc_html( $row->location_id ); ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $break_total ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $row->total_scheduled_hours, 2 ) ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $row->total_clocked_hours, 2 ) ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $payable_total, 2 ) ); ?></td>
                                <td><?php echo esc_html( $row->status ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">View/Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

        </div><!-- .wrap -->
        <?php
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

    // 2) Fetch API data for this header's week range
    $start = (string) $header->week_start_date;
    $end   = ! empty( $header->week_end_date )
        ? date( 'Y-m-d', strtotime( $header->week_end_date . ' +1 day' ) )
        : date( 'Y-m-d', strtotime( $header->week_start_date . ' +7 days' ) );

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

        // Verify "Week of" matches using the same Monday logic as sync
        try {
            $dt_week = new DateTime( $start_time_utc, new DateTimeZone( 'UTC' ) );
            $dt_week->setTimezone( $wp_timezone );

            $dayN = (int) $dt_week->format( 'N' ); // 1=Mon..7=Sun
            $days = ( $dayN <= 5 ) ? -( $dayN - 1 ) : ( 8 - $dayN );
            if ( $days !== 0 ) { $dt_week->modify( "{$days} days" ); }

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

    // 5) Delete entries + reset header totals/status
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

}

// Instantiate the core class
new WIW_Timesheet_Manager();

/**
 * Run installation routine on plugin activation.
 */
register_activation_hook( __FILE__, 'wiw_timesheet_manager_install' );
