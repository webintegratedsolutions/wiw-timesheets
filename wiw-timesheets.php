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

// Include the front-end enqueue file for client UI styles
require_once WIW_PLUGIN_PATH . 'includes/frontend-enqueue.php';

// Include the installation file for DB setup
require_once WIW_PLUGIN_PATH . 'includes/install.php';

// Include the admin settings trait
require_once WIW_PLUGIN_PATH . 'includes/admin-settings.php';

// Include the admin login handler
require_once WIW_PLUGIN_PATH . 'includes/admin-login-handler.php';

// Include the timesheet sync trait
require_once WIW_PLUGIN_PATH . 'includes/timesheet-helpers.php';

// Include the time formatting trait
require_once WIW_PLUGIN_PATH . 'includes/time-formatting-trait.php';

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

    // Include time formatting methods
    use WIWTS_Time_Formatting_Trait;

    public function __construct() {
        // 1. Add Admin Menus and Settings
        add_action( 'admin_menu', array( $this, 'add_admin_menus' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // 2. Add Front-end UI (Client Area) via Shortcodes (Still registered, but logic is deferred)
        add_shortcode( 'wiw_timesheets_client', array( $this, 'render_client_ui' ) );

        // NEW: Client Filter UI Shortcode
        add_shortcode( 'wiw_timesheets_client_filter', array( $this, 'render_client_filter_ui' ) );


        // 3. Handle AJAX requests for viewing/adjusting/approving (Crucial for interaction)
        // REMOVED: add_action( 'wp_ajax_wiw_fetch_timesheets', array( $this, 'handle_fetch_timesheets' ) );
        add_action( 'wp_ajax_wiw_approve_timesheet', array( $this, 'handle_approve_timesheet' ) );
        // NOTE: The 'nopriv' hook is generally NOT used for secure client areas.

        // 4. NEW: AJAX for local entry hours update (Local Timesheets view)
        add_action( 'wp_ajax_wiw_local_update_entry', array( $this, 'ajax_local_update_entry' ) );

        // NEW: AJAX for client entry hours update (Client UI)
        add_action( 'wp_ajax_wiw_client_update_entry', array( $this, 'ajax_client_update_entry' ) );

        // NEW: AJAX for client entry reset (Client UI)
        add_action( 'wp_ajax_wiw_client_reset_entry_from_api', array( $this, 'ajax_client_reset_entry_from_api' ) );

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
     * Front-end shortcode: [wiw_timesheets_client]
     * Minimal client UI for now (Step 1): shows scoped record count.
     */
public function render_client_ui() {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view timesheets.</p>';
    }

    $current_user_id = get_current_user_id();
    $client_id_raw   = get_user_meta( $current_user_id, 'client_account_number', true );
    $client_id       = is_scalar( $client_id_raw ) ? trim( (string) $client_id_raw ) : '';

    // Always show the client account number for now (debug).
        // Role indicator shown at the top of the client UI output.
    $current_user_role_label = 'Client';
    if ( current_user_can( 'manage_options' ) ) {
        $current_user_role_label = 'Administrator';
    }

    $out  = '<div class="wiw-client-timesheets">';
    $out .= '<div class="wiw-client-role-indicator" style="margin:0 0 10px 0;font-size:13px;color:#555;">Logged in as: <strong>' . esc_html( $current_user_role_label ) . '</strong></div>';
    $out .= '<div id="wiwts-client-ajax" data-ajax-url="' . esc_attr( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wiw_local_edit_entry' ) ) . '" data-nonce-approve="' . esc_attr( wp_create_nonce( 'wiw_local_approve_entry' ) ) . '" data-nonce-reset="' . esc_attr( wp_create_nonce( 'wiw_client_reset_entry_from_api' ) ) . '"></div>';

    if ( $client_id === '' ) {
        $out .= '<p>No client account number found on your user profile.</p>';
        $out .= '</div>';
        return $out;
    }

$timesheets = $this->get_scoped_local_timesheets( $client_id );

// Filter-aware summary (count reflects filtered results)
$filter_emp    = isset( $_GET['wiw_emp'] ) ? sanitize_text_field( wp_unslash( $_GET['wiw_emp'] ) ) : '';
$filter_period = isset( $_GET['wiw_period'] ) ? sanitize_text_field( wp_unslash( $_GET['wiw_period'] ) ) : '';

// Defaults
$employee_label   = 'All Employees';
$pay_period_label = 'All Pay Periods';

// Determine employee label from data
if ( $filter_emp !== '' ) {
    foreach ( $timesheets as $ts_row ) {
        if ( isset( $ts_row->employee_id ) && (string) $ts_row->employee_id === (string) $filter_emp ) {
            $employee_label = $ts_row->employee_name ?? 'Selected Employee';
            break;
        }
    }
}

// Determine pay period label
if ( $filter_period !== '' ) {
    $parts = explode( '|', $filter_period );
    if ( ! empty( $parts[0] ) ) {
        $pay_period_label = 'Pay Period ' . $parts[0] . ( ! empty( $parts[1] ) ? ' to ' . $parts[1] : '' );
    }
}

// Count timesheets AFTER filters
$filtered_count = 0;
foreach ( $timesheets as $ts_row ) {
    $row_emp_id = isset( $ts_row->employee_id ) ? (string) $ts_row->employee_id : '';
    $row_ws     = isset( $ts_row->week_start_date ) ? (string) $ts_row->week_start_date : '';
    $row_we     = isset( $ts_row->week_end_date ) ? (string) $ts_row->week_end_date : '';
    $row_period = $row_ws . '|' . $row_we;

    if ( $filter_emp !== '' && $row_emp_id !== (string) $filter_emp ) {
        continue;
    }
    if ( $filter_period !== '' && $row_period !== (string) $filter_period ) {
        continue;
    }
    $filtered_count++;
}

$timesheet_word = ( $filtered_count === 1 ) ? 'timesheet' : 'timesheets';

// Output summary
$out .= '<p class="wiw-muted" style="margin:6px 0 12px; font-size:13px; font-weight:normal;">'
    . 'Total of '
    . '<strong>' . esc_html( $filtered_count . ' ' . $timesheet_word . ' found' ) . '</strong>'
    . ' for '
    . esc_html( $employee_label )
    . ' and '
    . esc_html( $pay_period_label )
    . '.'
    . '</p>';

    if ( empty( $timesheets ) ) {
        $out .= '<p>No timesheets found for your account.</p>';
        $out .= '</div>';
        return $out;
    }

// Group by Employee -> Pay Period (Week Start/End).
$grouped = array();

foreach ( $timesheets as $ts ) {
    $employee_id   = isset( $ts->employee_id ) ? (string) $ts->employee_id : '';
    $employee_name = isset( $ts->employee_name ) ? (string) $ts->employee_name : '';
    if ( $filter_emp !== '' && (string) $employee_id !== (string) $filter_emp ) {
        continue;
    }

    $emp_key = $employee_id !== '' ? $employee_id : md5( $employee_name );

    $week_start = isset( $ts->week_start_date ) ? (string) $ts->week_start_date : '';
    $week_end   = isset( $ts->week_end_date ) ? (string) $ts->week_end_date : '';
    $period_key = $week_start . '|' . $week_end;
    if ( $filter_period !== '' && (string) $period_key !== (string) $filter_period ) {
        continue;
    }

    if ( ! isset( $grouped[ $emp_key ] ) ) {
        $grouped[ $emp_key ] = array(
            'employee_id'   => $employee_id,
            'employee_name' => $employee_name,
            'periods'       => array(),
        );
    }

    if ( ! isset( $grouped[ $emp_key ]['periods'][ $period_key ] ) ) {
        $grouped[ $emp_key ]['periods'][ $period_key ] = array(
            'week_start' => $week_start,
            'week_end'   => $week_end,
            'rows'       => array(),
        );
    }

    $grouped[ $emp_key ]['periods'][ $period_key ]['rows'][] = $ts;
}

// Sort employees by name.
uasort(
    $grouped,
    function( $a, $b ) {
        return strcasecmp( (string) $a['employee_name'], (string) $b['employee_name'] );
    }
);

// Render grouped tables.
foreach ( $grouped as $emp_group ) {
    $out .= '<h2 style="margin-top:24px;">👤 Employee: ' . esc_html( $emp_group['employee_name'] ) . '</h2>';

    // Sort pay periods newest first by week_start.
    $periods = $emp_group['periods'];
    uasort(
        $periods,
        function( $a, $b ) {
            return strcmp( (string) $b['week_start'], (string) $a['week_start'] );
        }
    );

    foreach ( $periods as $period ) {
        $pay_period_label = trim( (string) $period['week_start'] ) . ( $period['week_end'] ? ' to ' . trim( (string) $period['week_end'] ) : '' );

// Each Employee + Pay Period corresponds to a single Timesheet ID.
$timesheet_id_for_period = '';
if ( ! empty( $period['rows'] ) && isset( $period['rows'][0]->id ) ) {
    $timesheet_id_for_period = (string) absint( $period['rows'][0]->id );
}

$ts_label = $timesheet_id_for_period !== ''
    ? ' | Timesheet #' . $timesheet_id_for_period
    : '';

if ( current_user_can( 'manage_options' ) ) {
    $out .= '<h3 style="margin:12px 0 8px;">🗓️ Pay Period: '
        . esc_html( $pay_period_label . $ts_label )
        . '</h3>';
} else {
    $out .= '<h3 style="margin:12px 0 8px;">🗓️ Shifts from: '
        . esc_html( $pay_period_label )
        . '</h3>';
}

// Timesheet Details (shown between Pay Period header and the table)
$ts_header = ! empty( $period['rows'] ) ? $period['rows'][0] : null;

$ts_status  = ( $ts_header && isset( $ts_header->status ) ) ? (string) $ts_header->status : '';
$ts_created = ( $ts_header && isset( $ts_header->created_at ) ) ? (string) $ts_header->created_at : '';
$ts_updated = ( $ts_header && isset( $ts_header->updated_at ) ) ? (string) $ts_header->updated_at : '';

$ts_total_sched  = ( $ts_header && isset( $ts_header->total_scheduled_hours ) ) ? (string) $ts_header->total_scheduled_hours : '0.00';
$ts_total_clock  = ( $ts_header && isset( $ts_header->total_clocked_hours ) ) ? (string) $ts_header->total_clocked_hours : '0.00';

// Optional: sum payable hours from daily records view/table (matches backend data where available).
$ts_total_payable = '0.00';
if ( $timesheet_id_for_period !== '' ) {
	global $wpdb;
	$table_daily = $wpdb->prefix . 'wiw_daily_records';
	$sum_payable = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT SUM(payable_hours) FROM {$table_daily} WHERE timesheet_id = %d AND location_id = %s",
			absint( $timesheet_id_for_period ),
			$client_id
		)
	);
	if ( $sum_payable !== null && $sum_payable !== '' ) {
		$ts_total_payable = (string) $sum_payable;
	}
}

// Build Actions buttons (nonfunctional for now).
$actions_html  = '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
$actions_html .= '<a href="#" class="wiw-btn" onclick="return false;" aria-disabled="true">Sign Off</a>';
$actions_html .= '<a href="#" class="wiw-btn secondary" onclick="return false;" aria-disabled="true">Reset</a>';
$actions_html .= '</div>';

if ( current_user_can( 'manage_options' ) ) {
$out .= '<table class="wp-list-table widefat fixed striped wiw-timesheet-details" style="margin:8px 0 14px;">';
$out .= '<tbody>';

// Created/Updated (formatted like admin: WP timezone + date/time format)
$created_display = $this->wiw_format_datetime_local_pretty( $ts_created );
$updated_display = $this->wiw_format_datetime_local_pretty( $ts_updated );

// Location (Name + Address), matching admin Locations page
$ts_location_id = ( $ts_header && isset( $ts_header->location_id ) ) ? (string) $ts_header->location_id : (string) $client_id;
$loc            = $this->wiw_get_location_name_address_by_id( $ts_location_id );
$loc_name       = isset( $loc['name'] ) ? (string) $loc['name'] : 'N/A';
$loc_address    = isset( $loc['address'] ) ? (string) $loc['address'] : 'N/A';

$out .= '<tr><th style="width:180px;">Created/Updated</th><td>'
    . esc_html( $created_display )
    . ' / '
    . esc_html( $updated_display )
    . '</td></tr>';

$out .= '<tr><th>Location</th><td>'
    . '<span class="wiw-muted">' . esc_html( $loc_address ) . '</span>'
    . '</td></tr>';

$out .= '<tr><th>Totals</th><td>'
    . 'Sched: <strong>' . esc_html( $ts_total_sched ) . '</strong> | '
    . 'Clocked: <strong>' . esc_html( $ts_total_clock ) . '</strong> | '
    . 'Payable: <strong>' . esc_html( $ts_total_payable ) . '</strong>'
    . '</td></tr>';

$out .= '<tr><th>Status</th><td>' . esc_html( $ts_status !== '' ? $ts_status : 'N/A' ) . '</td></tr>';

$out .= '<tr><th>Actions</th><td>' . $actions_html . '</td></tr>';

$out .= '</tbody></table>';
}

        $out .= '<table class="wp-list-table widefat fixed striped" style="margin-bottom:16px;">';
        $out .= '<thead><tr>';
$out .= '<th>Shift Date</th>';
$out .= '<th>Sched. Start/End</th>';
$out .= '<th>Clock In</th>';
$out .= '<th>Clock Out</th>';
$out .= '<th>Break (Min)</th>';
$out .= '<th>Sched. Hrs</th>';
$out .= '<th>Clocked Hrs</th>';
$out .= '<th>Payable Hrs</th>';
$out .= '<th>Actions</th>';

        $out .= '<tbody>';

        foreach ( $period['rows'] as $ts ) {
            $id = isset( $ts->id ) ? (string) $ts->id : '';

            // These exist in wp_wiw_timesheets:
            $employee_name = isset( $ts->employee_name ) ? (string) $ts->employee_name : '';
            $location_name = isset( $ts->location_name ) ? (string) $ts->location_name : '';
            $status        = isset( $ts->status ) ? (string) $ts->status : '';

            $week_start = isset( $ts->week_start_date ) ? (string) $ts->week_start_date : '';
            $week_end   = isset( $ts->week_end_date ) ? (string) $ts->week_end_date : '';

            $sched_hrs   = isset( $ts->total_scheduled_hours ) ? (string) $ts->total_scheduled_hours : '0.00';
            $clocked_hrs = isset( $ts->total_clocked_hours ) ? (string) $ts->total_clocked_hours : '0.00';

            // These come from daily records (not available yet on this install):
            $sched_start_end = 'N/A';
            $clock_in_out    = 'N/A';
            $break_min       = 'N/A';
            $payable_hrs     = 'N/A';

            // Date column: at timesheet level we only have week_start_date; use that for now.
            $date_display = $week_start;

            // Actions: placeholder for now (we’ll wire details once daily records exist).
            $actions_html = '<span style="color:#666;">N/A</span>';

$timesheet_id = isset( $ts->id ) ? absint( $ts->id ) : 0;
$daily_rows   = $this->get_scoped_daily_records_for_timesheet( $client_id, $timesheet_id );

// If no daily rows exist, still show a single summary row using the timesheet header.
if ( empty( $daily_rows ) ) {
    $week_start = isset( $ts->week_start_date ) ? (string) $ts->week_start_date : '';
    $week_end   = isset( $ts->week_end_date ) ? (string) $ts->week_end_date : '';
    $pay_period = $week_start . ( $week_end ? ' to ' . $week_end : '' );

    $out .= '<tr>';
    $out .= '<td>N/A</td>';
    $out .= '<td>N/A</td>';
    $out .= '<td>N/A</td>';
    $out .= '<td>N/A</td>';
    $out .= '<td>' . esc_html( (string) ( $ts->total_scheduled_hours ?? '0.00' ) ) . '</td>';
    $out .= '<td>' . esc_html( (string) ( $ts->total_clocked_hours ?? '0.00' ) ) . '</td>';
    $out .= '<td>N/A</td>';
    $out .= '<td>' . esc_html( (string) ( $ts->status ?? '' ) ) . '</td>';
    $out .= '<td><span class="wiw-muted">N/A</span></td>';
    $out .= '</tr>';

} else {
    // Render one row per daily record (matches Local Timesheets view behavior).
    foreach ( $daily_rows as $dr ) {
        $week_start = isset( $ts->week_start_date ) ? (string) $ts->week_start_date : '';
        $week_end   = isset( $ts->week_end_date ) ? (string) $ts->week_end_date : '';
        $pay_period = $week_start . ( $week_end ? ' to ' . $week_end : '' );

        $date_display = isset( $dr->date ) ? (string) $dr->date : 'N/A';

        $sched_start = isset( $dr->scheduled_start ) ? (string) $dr->scheduled_start : '';
        $sched_end   = isset( $dr->scheduled_end ) ? (string) $dr->scheduled_end : '';
        $sched_start_end = $this->wiw_format_time_range_local( $sched_start, $sched_end );

        $clock_in  = isset( $dr->clock_in ) ? (string) $dr->clock_in : '';
        $clock_out = isset( $dr->clock_out ) ? (string) $dr->clock_out : '';
        $clock_in_out = $this->wiw_format_time_range_local( $clock_in, $clock_out );


        $break_min = isset( $dr->break_minutes ) ? (string) $dr->break_minutes : '0';

        $sched_hrs   = isset( $dr->scheduled_hours ) ? (string) $dr->scheduled_hours : '0.00';
        $clocked_hrs = isset( $dr->clocked_hours ) ? (string) $dr->clocked_hours : '0.00';
        $payable_hrs = isset( $dr->payable_hours ) ? (string) $dr->payable_hours : '0.00';

        $status_raw = isset( $dr->status ) ? (string) $dr->status : '';
        $status     = strtolower( trim( $status_raw ) );

        $out .= '<tr>';
        $out .= '<td>' . esc_html( $date_display ) . '</td>';
$out .= '<td>' . esc_html( $sched_start_end ) . '</td>';

// Raw HH:MM for edit inputs (local DATETIME -> HH:MM)
$clock_in_raw  = ( $clock_in && strlen( (string) $clock_in ) >= 16 ) ? substr( (string) $clock_in, 11, 5 ) : '';
$clock_out_raw = ( $clock_out && strlen( (string) $clock_out ) >= 16 ) ? substr( (string) $clock_out, 11, 5 ) : '';

// Display values (existing helper formatting)
$clock_in_display  = $this->wiw_format_time_local( $clock_in );
$clock_out_display = $this->wiw_format_time_local( $clock_out );

$out .= '<td class="wiw-client-cell-clock-in" data-orig="' . esc_attr( $clock_in_raw ) . '">'
    . '<span class="wiw-client-view">' . esc_html( $clock_in_display !== '' ? $clock_in_display : 'N/A' ) . '</span>'
    . '<input class="wiw-client-edit" type="text" inputmode="numeric" placeholder="HH:MM" value="' . esc_attr( $clock_in_raw ) . '" style="display:none; width:80px;" />'
    . '</td>';

$out .= '<td class="wiw-client-cell-clock-out" data-orig="' . esc_attr( $clock_out_raw ) . '">'
    . '<span class="wiw-client-view">' . esc_html( $clock_out_display !== '' ? $clock_out_display : 'N/A' ) . '</span>'
    . '<input class="wiw-client-edit" type="text" inputmode="numeric" placeholder="HH:MM" value="' . esc_attr( $clock_out_raw ) . '" style="display:none; width:80px;" />'
    . '</td>';

$out .= '<td class="wiw-client-cell-break" data-orig="' . esc_attr( (string) $break_min ) . '">'
    . '<span class="wiw-client-view">' . esc_html( (string) $break_min ) . '</span>'
    . '<input class="wiw-client-edit" type="text" inputmode="numeric" placeholder="0" value="' . esc_attr( (string) $break_min ) . '" style="display:none; width:70px;" />'
    . '</td>';

        $out .= '<td>' . esc_html( $sched_hrs ) . '</td>';
        $out .= '<td>' . esc_html( $clocked_hrs ) . '</td>';
        $out .= '<td>' . esc_html( $payable_hrs ) . '</td>';

$detail_rows = array(
    'Timesheet ID'              => (string) $timesheet_id,
    'Daily Record ID'           => $dr_id,
    'WIW Time ID'               => $wiw_time_id,
    'WIW Shift ID'              => $wiw_shift_id,
    'Shift Date'                => $date_display,
    'Scheduled Start/End (fmt)' => $fmt_sched_range,
    'Scheduled Start (raw)'     => $raw_sched_start !== '' ? $raw_sched_start : 'N/A',
    'Scheduled End (raw)'       => $raw_sched_end !== '' ? $raw_sched_end : 'N/A',
    'Clock In/Out (fmt)'        => $fmt_clock_range,
    'Clock In (raw)'            => $raw_clock_in !== '' ? $raw_clock_in : 'N/A',
    'Clock Out (raw)'           => $raw_clock_out !== '' ? $raw_clock_out : 'N/A',
    'Break (Min)'               => $break_min,
    'Sched. Hrs'                => $sched_hrs,
    'Clocked Hrs'               => $clocked_hrs,
    'Payable Hrs'               => $payable_hrs,
    'Status'                    => $status,
);

$details_html  = '<details>';
$details_html .= '<summary><span class="wiw-btn secondary">View</span></summary>';
$details_html .= '<div style="margin-top:8px; padding:10px; border:1px solid #ccd0d4; background:#fff;">';
$details_html .= '<table style="width:100%; border-collapse:collapse;">';

foreach ( $detail_rows as $label => $value ) {
    $details_html .= '<tr>';
    $details_html .= '<th style="text-align:left; padding:6px 8px; width:220px; border-top:1px solid #eee;">' . esc_html( $label ) . '</th>';
    $details_html .= '<td style="padding:6px 8px; border-top:1px solid #eee;">' . esc_html( (string) $value ) . '</td>';
    $details_html .= '</tr>';
}

$details_html .= '</table>';
$details_html .= '</div>';
$details_html .= '</details>';

$is_approved      = ( strtolower( (string) $status ) === 'approved' );
$approve_disabled = $is_approved ? ' disabled="disabled"' : '';
$approve_label    = $is_approved ? 'Approved' : 'Approve';

// Unresolved flags (use the same Description text shown in the expandable Flags table).
// Cached by Timesheet ID to avoid repeated queries per row.
static $wiwts_client_flags_unresolved_cache = array();

$tid_for_flags = isset( $timesheet_id ) ? absint( $timesheet_id ) : 0;

if ( $tid_for_flags > 0 && ! array_key_exists( $tid_for_flags, $wiwts_client_flags_unresolved_cache ) ) {
    $unresolved_descs = array();

    $flags_for_ts = $this->get_scoped_flags_for_timesheet( $client_id, $tid_for_flags );
    if ( ! empty( $flags_for_ts ) ) {
        foreach ( $flags_for_ts as $fg ) {
            $fg_status = isset( $fg->flag_status ) ? (string) $fg->flag_status : '';
            if ( $fg_status === 'resolved' ) {
                continue;
            }

            // IMPORTANT: match the expandable table's "Description" column exactly.
            $fg_desc = isset( $fg->description ) ? (string) $fg->description : '';
            $fg_desc = trim( $fg_desc );

            if ( $fg_desc !== '' ) {
                $unresolved_descs[] = $fg_desc;
            }
        }
    }

    $wiwts_client_flags_unresolved_cache[ $tid_for_flags ] = $unresolved_descs;
}

$unresolved_flags_attr = '';
if ( $tid_for_flags > 0 && ! empty( $wiwts_client_flags_unresolved_cache[ $tid_for_flags ] ) ) {
    // Use a safe delimiter; JS will split this into bullet lines.
    $unresolved_flags_attr = ' data-unresolved-flags="' . esc_attr( implode( '||', $wiwts_client_flags_unresolved_cache[ $tid_for_flags ] ) ) . '"';
}

$actions_html  = '<div class="wiw-client-actions" style="display:flex;flex-direction:column;gap:6px;">';
if ( ! $is_approved ) {
    $actions_html .= '<button type="button" class="wiw-btn secondary wiw-client-edit-btn">Edit</button>';
}
$actions_html .= '<button type="button" class="wiw-btn secondary wiw-client-approve-btn" data-entry-id="' . esc_attr( isset( $dr->id ) ? absint( $dr->id ) : 0 ) . '"' . $unresolved_flags_attr . $approve_disabled . '>' . esc_html( $approve_label ) . '</button>';
$actions_html .= '<button type="button" class="wiw-btn wiw-client-save-btn" style="display:none;" data-entry-id="' . esc_attr( isset( $dr->id ) ? absint( $dr->id ) : 0 ) . '">Save</button>';
$actions_html .= '<button type="button" class="wiw-btn secondary wiw-client-reset-btn" style="display:none;">Reset</button>';
$actions_html .= '<button type="button" class="wiw-btn secondary wiw-client-cancel-btn" style="display:none;">Cancel</button>';
$actions_html .= '</div>';



$out .= '<td>' . $actions_html . '</td>';


        $out .= '</tr>';
    }
}

        }

        $out .= '</tbody></table>';

        // Expandable edit logs (per-timesheet, shown under each daily table when that timesheet is open).
        if ( isset( $timesheet_id_for_period ) && $timesheet_id_for_period !== '' ) {
        	$edit_logs = $this->get_scoped_edit_logs_for_timesheet( $client_id, absint( $timesheet_id_for_period ) );

            if ( current_user_can( 'manage_options' ) ) {
        	$out .= '<details class="wiw-edit-logs" style="margin:12px 0 22px;">';
        	$out .= '<summary>💡 Click to Expand: Edit Logs</summary>';
        	$out .= '<div style="padding-top:8px;">';

        	if ( empty( $edit_logs ) ) {
        		$out .= '<p class="description" style="margin:0;">No edit logs found for this timesheet.</p>';
        	} else {
        		$out .= '<table class="wp-list-table widefat fixed striped wiw-edit-logs-table">';
        		$out .= '<thead><tr>';
        		$out .= '<th>When</th>';
        		$out .= '<th>Modified</th>';
        		$out .= '<th>Old</th>';
        		$out .= '<th>New</th>';
        		$out .= '<th>Edited By</th>';
        		$out .= '</tr></thead>';
        		$out .= '<tbody>';

        		foreach ( $edit_logs as $lg ) {
        			$when = isset( $lg->created_at ) ? $this->wiw_format_datetime_local_pretty( (string) $lg->created_at ) : '';

        			$field = isset( $lg->edit_type ) ? (string) $lg->edit_type : '';
        			$oldv  = isset( $lg->old_value ) ? (string) $lg->old_value : '';
        			$newv  = isset( $lg->new_value ) ? (string) $lg->new_value : '';

// Client-friendly display: show time only (12-hour) when value is a datetime.
$oldv_norm = $this->normalize_datetime_to_minute( $oldv );
$newv_norm = $this->normalize_datetime_to_minute( $newv );

$oldv_disp = $oldv_norm;
$newv_disp = $newv_norm;

if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $oldv_norm ) ) {
	$oldv_disp = date_i18n( 'g:i a', strtotime( $oldv_norm ) );
}

if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $newv_norm ) ) {
	$newv_disp = date_i18n( 'g:i a', strtotime( $newv_norm ) );
}

        			$who = '';
        			if ( ! empty( $lg->edited_by_display_name ) ) {
        				$who = (string) $lg->edited_by_display_name;
        			} elseif ( ! empty( $lg->edited_by_user_login ) ) {
        				$who = (string) $lg->edited_by_user_login;
        			}

        			$out .= '<tr>';
        			$out .= '<td>' . esc_html( $when !== '' ? $when : 'N/A' ) . '</td>';
        			$out .= '<td><strong>' . esc_html( $field !== '' ? $field : 'N/A' ) . '</strong></td>';
        			$out .= '<td>' . esc_html( $oldv_disp !== '' ? $oldv_disp : 'N/A' ) . '</td>';
        			$out .= '<td>' . esc_html( $newv_disp !== '' ? $newv_disp : 'N/A' ) . '</td>';
        			$out .= '<td>' . esc_html( $who !== '' ? $who : 'N/A' ) . '</td>';
        			$out .= '</tr>';
        		}

        		$out .= '</tbody></table>';
        	}

        	$out .= '</div>';
$out .= '</details>';
}
// Expandable flags (per-timesheet, shown under each daily table when that timesheet is open).
$flags = $this->get_scoped_flags_for_timesheet( $client_id, absint( $timesheet_id_for_period ) );

$has_unresolved_flags = false;
if ( ! empty( $flags ) ) {
	foreach ( $flags as $fg ) {
		$status = isset( $fg->flag_status ) ? (string) $fg->flag_status : '';
		if ( $status !== 'resolved' ) {
			$has_unresolved_flags = true;
			break;
		}
	}
}

$flag_icon = $has_unresolved_flags ? '🟠' : '🟢';
$flag_count = is_array( $flags ) ? count( $flags ) : 0;

$out .= '<details class="wiw-flags" style="margin:12px 0 22px;">';
$out .= '<summary>' . $flag_icon . ' Click to Expand: Flags</summary>';
$out .= '<div style="padding-top:8px;">';

if ( empty( $flags ) ) {
	$out .= '<p class="description" style="margin:0;">No flags found for this timesheet.</p>';
} else {
	// Border wrapper (requested).
	$out .= '<div style="border:1px solid #ccd0d4; border-radius:4px; overflow:hidden; background:#fff;">';
	$out .= '<table class="wp-list-table widefat fixed striped" style="margin:0;">';
	$out .= '<thead><tr>';
	$out .= '<th style="width:110px;">Type</th>';
	$out .= '<th>Description</th>';
	$out .= '<th style="width:120px;">Status</th>';
	$out .= '<th style="width:220px;">Updated</th>';
	$out .= '</tr></thead>';
	$out .= '<tbody>';

	foreach ( $flags as $fg ) {
		$type   = isset( $fg->flag_type ) ? (string) $fg->flag_type : '';
		$desc   = isset( $fg->description ) ? (string) $fg->description : '';
		$status = isset( $fg->flag_status ) ? (string) $fg->flag_status : '';

		$updated_raw = isset( $fg->updated_at ) ? (string) $fg->updated_at : '';
		$updated     = $updated_raw !== '' ? $this->wiw_format_datetime_local_pretty( $updated_raw ) : 'N/A';

		// Orange for unresolved, green for resolved (requested).
		$row_style = ( $status === 'resolved' )
			? 'background:#dff0d8;'
			: 'background:#fff3cd;';

		// Equal spacing on rows (requested).
		$cell_style = 'style="padding:10px 10px; vertical-align:top;"';

		$out .= '<tr style="' . esc_attr( $row_style ) . '">';
		$out .= '<td ' . $cell_style . '><strong>' . esc_html( $type !== '' ? $type : 'N/A' ) . '</strong></td>';
		$out .= '<td ' . $cell_style . '>' . esc_html( $desc !== '' ? $desc : 'N/A' ) . '</td>';
		$out .= '<td ' . $cell_style . '>' . esc_html( $status !== '' ? $status : 'N/A' ) . '</td>';
		$out .= '<td ' . $cell_style . '>' . esc_html( $updated ) . '</td>';
		$out .= '</tr>';
	}

	$out .= '</tbody></table>';
	$out .= '</div>';
}

$out .= '</div>';
$out .= '</details>';
$out .= '<hr class="wiw-edit-logs-separator" />';

        }

    }
}


// Expandable legend/reference (shown once below all tables).
$out .= '<hr style="margin:24px 0;" />';
$out .= '<details class="wiw-legend">';
$out .= '<summary>';
$out .= '💡 Click to Expand: Field Reference Legend';
$out .= '</summary>';

$out .= '<div style="padding-top: 8px;">';
$out .= '<table class="wp-list-table widefat fixed striped">';
$out .= '<thead><tr><th class="field-col">Field</th><th>Description</th></tr></thead>';
$out .= '<tbody>';

$out .= '<tr><td><strong>Shift Date</strong></td><td>The date of the shift entry. This corresponds to the specific day the time record applies to within the pay period.</td></tr>';
$out .= '<tr><td><strong>Sched. Start/End</strong></td><td>The scheduled shift start and end time (local time). If the shift schedule is not available, this will show <em>N/A</em>.</td></tr>';
$out .= '<tr><td><strong>Clock In</strong></td><td>The clock-in time for the shift entry (if available).</td></tr>';
$out .= '<tr><td><strong>Clock Out</strong></td><td>The clock-out time for the shift entry (if available). If missing, it may indicate an active shift.</td></tr>';
$out .= '<tr><td><strong>Break (Min)</strong></td><td>Total break time deducted, shown in minutes.</td></tr>';
$out .= '<tr><td><strong>Sched. Hrs</strong></td><td>Total scheduled hours for the shift entry.</td></tr>';
$out .= '<tr><td><strong>Clocked Hrs</strong></td><td>Total hours actually worked for the shift entry, based on clock-in/clock-out minus breaks.</td></tr>';
$out .= '<tr><td><strong>Payable Hrs</strong></td><td>Total hours payable for the shift entry. This is typically the clocked hours after break deductions and any business rules applied.</td></tr>';
$out .= '<tr><td><strong>Status</strong></td><td>The approval status of the entry (for example: <em>pending</em> or <em>approved</em>).</td></tr>';

$out .= '</tbody></table>';
$out .= '</div>';
$out .= '</details>';

/**
 * Client UI inline edit behavior (UI only — no persistence yet)
 */
$out .= '<script>
(function(){
  function closestRow(el){
    while(el && el.tagName && el.tagName.toLowerCase() !== "tr"){ el = el.parentNode; }
    return el;
  }

  function setEditing(row, isEditing){
    var inputs = row.querySelectorAll("input.wiw-client-edit");
    var views  = row.querySelectorAll("span.wiw-client-view");

    for (var i=0;i<inputs.length;i++){ inputs[i].style.display = isEditing ? "" : "none"; }
    for (var j=0;j<views.length;j++){ views[j].style.display = isEditing ? "none" : ""; }

    var editBtn    = row.querySelector(".wiw-client-edit-btn");
    var saveBtn    = row.querySelector(".wiw-client-save-btn");
    var resetBtn   = row.querySelector(".wiw-client-reset-btn");
    var cancelBtn  = row.querySelector(".wiw-client-cancel-btn");
    var approveBtn = row.querySelector(".wiw-client-approve-btn");

    if (editBtn)   editBtn.style.display   = isEditing ? "none" : "";
    if (saveBtn)   saveBtn.style.display   = isEditing ? "" : "none";
    if (resetBtn)  resetBtn.style.display  = isEditing ? "" : "none";
    if (cancelBtn) cancelBtn.style.display = isEditing ? "" : "none";

    // Universal: hide Approve during edit mode, restore after.
    if (approveBtn) {
        approveBtn.style.display = isEditing ? "none" : "";
    }

  }

  function updateViewFromInputs(row){
    // Update the visible spans from current input values (no validation/persistence yet)
    var cellIn    = row.querySelector("td.wiw-client-cell-clock-in");
    var cellOut   = row.querySelector("td.wiw-client-cell-clock-out");
    var cellBreak = row.querySelector("td.wiw-client-cell-break");

    if (cellIn){
      var inInput = cellIn.querySelector("input.wiw-client-edit");
      var inView  = cellIn.querySelector("span.wiw-client-view");
      if (inInput && inView){ inView.textContent = inInput.value ? inInput.value : "N/A"; }
    }
    if (cellOut){
      var outInput = cellOut.querySelector("input.wiw-client-edit");
      var outView  = cellOut.querySelector("span.wiw-client-view");
      if (outInput && outView){ outView.textContent = outInput.value ? outInput.value : "N/A"; }
    }
    if (cellBreak){
      var bInput = cellBreak.querySelector("input.wiw-client-edit");
      var bView  = cellBreak.querySelector("span.wiw-client-view");
      if (bInput && bView){ bView.textContent = bInput.value ? bInput.value : "0"; }
    }
  }

  function restoreOriginal(row){
    var cells = [
      row.querySelector("td.wiw-client-cell-clock-in"),
      row.querySelector("td.wiw-client-cell-clock-out"),
      row.querySelector("td.wiw-client-cell-break")
    ];

    for (var i=0;i<cells.length;i++){
      var cell = cells[i];
      if (!cell) continue;

      var orig = cell.getAttribute("data-orig") || "";
      var input = cell.querySelector("input.wiw-client-edit");
      var view  = cell.querySelector("span.wiw-client-view");

      if (input) input.value = orig;

      if (view){
        if (cell.classList.contains("wiw-client-cell-break")){
          view.textContent = orig ? orig : "0";
        } else {
          view.textContent = orig ? orig : "N/A";
        }
      }
    }
  }

  document.addEventListener("click", function(e){
    var t = e.target;

    if (t && t.classList && t.classList.contains("wiw-client-edit-btn")){
      e.preventDefault();
      var row = closestRow(t);
      if (!row) return;
      setEditing(row, true);
      return;
    }

if (t && t.classList && t.classList.contains("wiw-client-reset-btn")){
  e.preventDefault();

  var row = closestRow(t);
  if (!row) { alert("Row not found."); return; }

  var cfg = document.getElementById("wiwts-client-ajax");
  if (!cfg) { alert("Missing AJAX config."); return; }

  var ajaxUrl = cfg.getAttribute("data-ajax-url") || "";
  var nonceR  = cfg.getAttribute("data-nonce-reset") || "";

  if (!ajaxUrl || !nonceR) { alert("Missing reset AJAX settings."); return; }

  // Use the Save buttons entry id (it already exists on every row)
  var saveBtn = row.querySelector(".wiw-client-save-btn");
  var entryId = saveBtn ? (saveBtn.getAttribute("data-entry-id") || "") : "";

  var form = new FormData();
  form.append("action", "wiw_client_reset_entry_from_api");
  form.append("security", nonceR);
  form.append("entry_id", entryId);

  fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: form })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (!data || !data.success){
        var msg = (data && data.data && data.data.message) ? data.data.message : "Reset failed.";
        alert(msg);
        return;
      }
var p = (data && data.data && data.data.preview) ? data.data.preview : null;

if (!p) {
  alert((data.data && data.data.message) ? data.data.message : "Reset preview loaded.");
  return;
}

var msg = "";
msg += (data.data && data.data.message) ? data.data.message : "Reset preview loaded.";
msg += "\n\nCurrent (Saved):";
msg += "\nClock In: " + (p.current && p.current.clock_in ? p.current.clock_in : "N/A");
msg += "\nClock Out: " + (p.current && p.current.clock_out ? p.current.clock_out : "N/A");
msg += "\nBreak (Min): " + (p.current && typeof p.current.break_minutes !== "undefined" ? p.current.break_minutes : "0");

msg += "\n\nFrom WIW (Would Reset To):";
msg += "\nClock In: " + (p.api && p.api.clock_in ? p.api.clock_in : "N/A");
msg += "\nClock Out: " + (p.api && p.api.clock_out ? p.api.clock_out : "N/A");
msg += "\nBreak (Min): " + (p.api && typeof p.api.break_minutes !== "undefined" ? p.api.break_minutes : "0");

if (!window.confirm(msg + "\n\nApply this reset now?")) {
  return;
}

var formData2 = new FormData();
formData2.append("action", "wiw_client_reset_entry_from_api");
formData2.append("security", nonceR);
formData2.append("entry_id", entryId);
formData2.append("apply_reset", "1");

fetch(ajaxUrl, {
  method: "POST",
  credentials: "same-origin",
  body: formData2
})
.then(function(r) { return r.json(); })
.then(function(resp2) {
  if (!resp2 || !resp2.success) {
    var m = (resp2 && resp2.data && resp2.data.message) ? resp2.data.message : "Reset failed.";
    alert(m);
    return;
  }
  window.location.reload();
})
.catch(function() {
  alert("Reset failed (network error).");
});

    })
    .catch(function(err){
      console.error(err);
      alert("Reset failed (network error).");
    });

  return;
}

    if (t && t.classList && t.classList.contains("wiw-client-cancel-btn")){
      e.preventDefault();
      var row2 = closestRow(t);
      if (!row2) return;
      restoreOriginal(row2);
      setEditing(row2, false);
      return;
    }

if (t && t.classList && t.classList.contains("wiw-client-approve-btn")){
  e.preventDefault();

  if (t.disabled) { return; }

  var flagsRaw = t.getAttribute("data-unresolved-flags") || "";
var msg = "Approve this entry?";

if (flagsRaw) {
  var parts = flagsRaw.split("||").map(function(s){ return (s || "").trim(); }).filter(Boolean);
  if (parts.length) {
    msg = "This entry has unresolved flags:\n\n";
    parts.forEach(function(line){
      msg += "• " + line + "\n";
    });
    msg += "\nApprove this entry?";
  }
}

if (!confirm(msg)) { return; }


  var row = closestRow(t);
  if (!row) { alert("Approve clicked but row not found"); return; }

  var cfg = document.getElementById("wiwts-client-ajax");
  if (!cfg) { alert("Missing AJAX config"); return; }

  var ajaxUrl = cfg.getAttribute("data-ajax-url") || "";
  var nonceA  = cfg.getAttribute("data-nonce-approve") || "";
  if (!ajaxUrl || !nonceA) { alert("Missing AJAX url/approve nonce"); return; }

  var entryId = t.getAttribute("data-entry-id") || "";
  if (!entryId) { alert("Missing Entry ID"); return; }

  var form = new FormData();
  form.append("action", "wiw_local_approve_entry");
  form.append("security", nonceA);
  form.append("entry_id", entryId);

  fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: form })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (!data || !data.success){
        var msg = (data && data.data && data.data.message) ? data.data.message : "Approve failed.";
        alert(msg);
        return;
      }

      // Update the Status cell in the row.
      var statusCell = row.querySelector("td.wiw-client-cell-status");
      if (statusCell) statusCell.textContent = "approved";

      // Disable the approve button after success.
            // Mark approved in UI.
      t.textContent = "Approved";
      t.disabled = true;

      // Hide Edit button for this row.
      var editBtn = row.querySelector("button.wiw-client-edit-btn");
      if (editBtn) {
        editBtn.style.display = "none";
      }

    })
    .catch(function(err){
      console.error(err);
      alert("Approve failed (network error).");
    });

  return;
}

if (t && t.classList && t.classList.contains("wiw-client-save-btn")){
  e.preventDefault();

  var row = closestRow(t);
  if (!row) { alert("Save clicked but row not found"); return; }

  var cfg = document.getElementById("wiwts-client-ajax");
  if (!cfg) { alert("Missing AJAX config"); return; }

  var ajaxUrl = cfg.getAttribute("data-ajax-url") || "";
  var nonce = cfg.getAttribute("data-nonce") || "";
  if (!ajaxUrl || !nonce) { alert("Missing AJAX url/nonce"); return; }

  var entryId = t.getAttribute("data-entry-id") || "";
  if (!entryId) { alert("Missing Entry ID"); return; }

  var inEl = row.querySelector("td.wiw-client-cell-clock-in input.wiw-client-edit");
  var outEl = row.querySelector("td.wiw-client-cell-clock-out input.wiw-client-edit");
  var breakEl = row.querySelector("td.wiw-client-cell-break input.wiw-client-edit");

  var inVal = inEl ? (inEl.value || "").trim() : "";
  var outVal = outEl ? (outEl.value || "").trim() : "";
  var breakVal = breakEl ? (breakEl.value || "").trim() : "0";
  if (breakVal === "") breakVal = "0";

// Allow N/A (blank) times.
// Also accept 12-hour input like "8:04 am" and convert to 24-hour "08:04".
function normalizeTime(val) {
  val = (val || "").trim().toLowerCase();
  if (val === "") return "";

  // Already HH:MM (24-hour)
  if (/^\d{2}:\d{2}$/.test(val)) return val;

  // Accept H:MM am/pm or HH:MM am/pm
  var m = val.match(/^(\d{1,2}):(\d{2})\s*(am|pm)$/);
  if (!m) return "";

  var h = parseInt(m[1], 10);
  var mm = m[2];
  var ap = m[3];

  if (isNaN(h) || h < 1 || h > 12) return "";

  if (ap === "am") {
    if (h === 12) h = 0;
  } else { // pm
    if (h !== 12) h = h + 12;
  }

  var hh = String(h).padStart(2, "0");
  return hh + ":" + mm;
}

var rawIn = inVal;
var rawOut = outVal;

inVal = normalizeTime(inVal);
outVal = normalizeTime(outVal);

// If the user typed something non-empty but we could not normalize it, stop the save.
if (rawIn.trim() !== "" && inVal === "") {
  alert("Clock In/Out must be HH:MM (24-hour)");
  return;
}
if (rawOut.trim() !== "" && outVal === "") {
  alert("Clock In/Out must be HH:MM (24-hour)");
  return;
}

  if (!/^\d+$/.test(breakVal)) {
    alert("Break (Min) must be a whole number");
    return;
  }

var displayIn  = (inVal && inVal !== "") ? inVal : "N/A";
var displayOut = (outVal && outVal !== "") ? outVal : "N/A";

var ok = window.confirm(
  "Confirm Save?\n\n" +
  "Clock In: " + displayIn + "\n" +
  "Clock Out: " + displayOut + "\n" +
  "Break (Min): " + breakVal + "\n\n" +
  "Click OK to save, or Cancel to discard changes."
);

if (!ok) {
  return;
}


  var body = new URLSearchParams();
  body.append("action", "wiw_client_update_entry");
  body.append("security", nonce);
  body.append("entry_id", entryId);
  body.append("clock_in_time", inVal);
  body.append("clock_out_time", outVal);
  body.append("break_minutes", breakVal);

  fetch(ajaxUrl, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
    body: body.toString()
  })
  .then(function(r){ return r.json(); })
  .then(function(resp){
  if (!resp || !resp.success) {
    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : "Save failed";
    alert(msg);
    return;
  }

  var newTotal = (resp && resp.data && resp.data.total_clocked_hours !== undefined)
    ? resp.data.total_clocked_hours
    : "";

  alert("Saved successfully.\n\nNew Total Clocked Hours: " + newTotal);

  window.location.reload();
})
  .catch(function(){
    alert("AJAX error saving entry");
  });

  return;
}

  });
})();
</script>';

return $out;

}

/**
 * Front-end shortcode: [wiw_timesheets_client_filter]
 * Renders scoped dropdown filters (Employee, Pay Period) that drive the main table via query params.
 */
public function render_client_filter_ui() {
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $current_user_id = get_current_user_id();
    $client_id_raw   = get_user_meta( $current_user_id, 'client_account_number', true );
    $client_id       = is_scalar( $client_id_raw ) ? trim( (string) $client_id_raw ) : '';
    if ( $client_id === '' ) {
        return '';
    }

    // Pull scoped timesheets (local header rows) to populate dropdown options.
    $timesheets = $this->get_scoped_local_timesheets( $client_id );
    if ( empty( $timesheets ) ) {
        return '';
    }

// Current selections from query string (used to dynamically repopulate options).
$selected_emp    = isset( $_GET['wiw_emp'] ) ? sanitize_text_field( wp_unslash( $_GET['wiw_emp'] ) ) : '';
$selected_period = isset( $_GET['wiw_period'] ) ? sanitize_text_field( wp_unslash( $_GET['wiw_period'] ) ) : '';

// Build dynamic option sets based on selection.
// - If employee selected: show only periods that exist for that employee.
// - If period selected: show only employees that exist for that period.
// - If both selected: show intersection.
$employees = array(); // employee_id => employee_name
$periods   = array(); // "start|end" => "start to end"

foreach ( $timesheets as $ts ) {
    $eid   = isset( $ts->employee_id ) ? (string) $ts->employee_id : '';
    $ename = isset( $ts->employee_name ) ? (string) $ts->employee_name : '';

    $ws = isset( $ts->week_start_date ) ? (string) $ts->week_start_date : '';
    $we = isset( $ts->week_end_date ) ? (string) $ts->week_end_date : '';
    $pkey = ( $ws !== '' ) ? ( $ws . '|' . $we ) : '';

    // Filter logic for option generation:
    // If a pay period is selected, only include rows that match it (for employee option set).
    if ( $selected_period !== '' && $pkey !== $selected_period ) {
        // This row doesn't belong to the selected period.
        // Still allow period option generation below if employee is selected only.
        // We'll handle that by not adding employees for non-matching periods.
    }

    // If an employee is selected, only include rows that match it (for period option set).
    if ( $selected_emp !== '' && $eid !== $selected_emp ) {
        // Same concept: don't add periods for other employees.
    }

    // Add employee option only if it matches selected period (when selected).
    if ( $eid !== '' && $ename !== '' ) {
        if ( $selected_period === '' || $pkey === $selected_period ) {
            $employees[ $eid ] = $ename;
        }
    }

    // Add period option only if it matches selected employee (when selected).
    if ( $pkey !== '' ) {
        $plabel = $ws . ( $we ? ' to ' . $we : '' );
        if ( $selected_emp === '' || $eid === $selected_emp ) {
            $periods[ $pkey ] = $plabel;
        }
    }
}

    // Sort options for nicer UX.
    asort( $employees, SORT_NATURAL | SORT_FLAG_CASE );
    uasort(
        $periods,
        function( $a, $b ) {
            // Sort by the label start date desc (string compare works for YYYY-MM-DD)
            return strcmp( $b, $a );
        }
    );

    // Preserve other query args.
    $action_url = get_permalink();

    $out  = '<div class="wiw-client-timesheets" style="margin-bottom:14px;">';
    $out .= '<form method="get" action="' . esc_url( $action_url ) . '" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">';

    $out .= '<div>';
    $out .= '<label for="wiw_emp" style="display:block;font-weight:600;margin-bottom:4px;">Employee</label>';
    $out .= '<select id="wiw_emp" name="wiw_emp">';
    $out .= '<option value="">All Employees</option>';
    foreach ( $employees as $eid => $ename ) {
        $out .= '<option value="' . esc_attr( $eid ) . '"' . selected( $selected_emp, $eid, false ) . '>'
            . esc_html( $ename )
            . '</option>';
    }
    $out .= '</select>';
    $out .= '</div>';

    $out .= '<div>';
if ( current_user_can( 'manage_options' ) ) {
    $out .= '<label for="wiw_period" style="display:block;font-weight:600;margin-bottom:4px;">Pay Period</label>';
} else {
    $out .= '<label for="wiw_period" style="display:block;font-weight:600;margin-bottom:4px;">Shifts from:</label>';
}
    $out .= '<select id="wiw_period" name="wiw_period">';
if ( current_user_can( 'manage_options' ) ) {
    $out .= '<option value="">All Pay Periods</option>';
} else {
    $out .= '<option value="">Anytime</option>';
}
    foreach ( $periods as $pkey => $plabel ) {
        $out .= '<option value="' . esc_attr( $pkey ) . '"' . selected( $selected_period, $pkey, false ) . '>'
            . esc_html( $plabel )
            . '</option>';
    }
    $out .= '</select>';
    $out .= '</div>';

    $out .= '<div>';
    $out .= '<button type="submit" class="wiw-btn">Filter</button> ';
    $out .= '<a class="wiw-btn secondary" href="' . esc_url( remove_query_arg( array( 'wiw_emp', 'wiw_period' ), $action_url ) ) . '">Reset</a>';
    $out .= '</div>';

    $out .= '</form>';
    $out .= '</div>';

    $out .= '<hr style="margin:40px 0;" />';

    return $out;
}


/**
 * Fetch local timesheets from DB, always scoped to a client account number.
 * (No admin bypass; the shortcode is assumed to be used on a secure client page.)
 */
private function get_scoped_local_timesheets( $client_id ) {
    global $wpdb;

    $table_ts    = $wpdb->prefix . 'wiw_timesheets';
    $table_daily = $wpdb->prefix . 'wiw_daily_records';

    $client_id = is_scalar( $client_id ) ? trim( (string) $client_id ) : '';
    if ( $client_id === '' ) {
        return array();
    }

$sql = "
    SELECT ts.*,
           0 AS daily_record_count
    FROM {$table_ts} ts
    WHERE ts.location_id = %s
    ORDER BY ts.week_start_date DESC, ts.id DESC
";

    $prepared = $wpdb->prepare( $sql, $client_id );

$results = $wpdb->get_results( $prepared );

return $results;

}

/**
 * Fetch daily records for a given timesheet ID from the compatibility table/view.
 * Scoped by location_id to ensure client isolation.
 */
private function get_scoped_daily_records_for_timesheet( $client_id, $timesheet_id ) {
    global $wpdb;

    $table_daily = $wpdb->prefix . 'wiw_daily_records';

    $client_id    = is_scalar( $client_id ) ? trim( (string) $client_id ) : '';
    $timesheet_id = absint( $timesheet_id );

    if ( $client_id === '' || $timesheet_id <= 0 ) {
        return array();
    }

    $sql = "
        SELECT *
        FROM {$table_daily}
        WHERE timesheet_id = %d
          AND location_id = %s
        ORDER BY date ASC, id ASC
    ";

    $prepared = $wpdb->prepare( $sql, $timesheet_id, $client_id );

    return $wpdb->get_results( $prepared );
}


/**
 * Fetch edit logs for a given timesheet ID.
 * Scoped by location_id to ensure client isolation.
 */
private function get_scoped_edit_logs_for_timesheet( $client_id, $timesheet_id ) {
	global $wpdb;

	$table_logs = $wpdb->prefix . 'wiw_timesheet_edit_logs';
	$table_ts   = $wpdb->prefix . 'wiw_timesheets';

	$client_id    = is_scalar( $client_id ) ? trim( (string) $client_id ) : '';
	$timesheet_id = absint( $timesheet_id );

	if ( $client_id === '' || $timesheet_id <= 0 ) {
		return array();
	}

	// Join to timesheets to enforce location scope.
	$sql = "
		SELECT l.*
		FROM {$table_logs} l
		INNER JOIN {$table_ts} ts ON ts.id = l.timesheet_id
		WHERE l.timesheet_id = %d
		  AND ts.location_id = %s
		ORDER BY l.created_at DESC, l.id DESC
		LIMIT 200
	";

	$prepared = $wpdb->prepare( $sql, $timesheet_id, $client_id );

	return $wpdb->get_results( $prepared );
}

/**
 * Fetch flags for a given timesheet ID.
 * Scoped by location_id to ensure client isolation.
 *
 * Flags table is keyed by wiw_time_id, so we join to daily records to
 * get only flags that belong to this timesheet.
 */
private function get_scoped_flags_for_timesheet( $client_id, $timesheet_id ) {
	global $wpdb;

	$table_flags = $wpdb->prefix . 'wiw_timesheet_flags';
	$table_daily = $wpdb->prefix . 'wiw_daily_records';

	$client_id    = is_scalar( $client_id ) ? trim( (string) $client_id ) : '';
	$timesheet_id = absint( $timesheet_id );

	if ( $client_id === '' || $timesheet_id <= 0 ) {
		return array();
	}

	$sql = "
		SELECT f.*
		FROM {$table_flags} f
		INNER JOIN {$table_daily} d ON d.wiw_time_id = f.wiw_time_id
		WHERE d.timesheet_id = %d
		  AND d.location_id = %s
		ORDER BY
			CASE WHEN f.flag_status = 'resolved' THEN 1 ELSE 0 END ASC,
			f.updated_at DESC,
			f.id DESC
	";

	$prepared = $wpdb->prepare( $sql, $timesheet_id, $client_id );

	return $wpdb->get_results( $prepared );
}

/**
 * Fetch a location (site) name + address by location_id, matching admin Locations formatting.
 * Uses fetch_locations_data() and maps sites by ID.
 */
private function wiw_get_location_name_address_by_id( $location_id ) {
    $location_id = is_scalar( $location_id ) ? trim( (string) $location_id ) : '';
    if ( $location_id === '' ) {
        return array( 'name' => 'N/A', 'address' => 'N/A' );
    }

    // Cache per request to avoid repeated API calls.
    if ( ! isset( $this->wiw_site_map ) || ! is_array( $this->wiw_site_map ) ) {
        $this->wiw_site_map = array();

        $locations_data = $this->fetch_locations_data();
        if ( ! is_wp_error( $locations_data ) ) {
            $sites = isset( $locations_data->sites ) ? $locations_data->sites : array();
            foreach ( $sites as $site ) {
                if ( isset( $site->id ) ) {
                    $this->wiw_site_map[ (string) $site->id ] = $site;
                }
            }
        }
    }

    $site = isset( $this->wiw_site_map[ $location_id ] ) ? $this->wiw_site_map[ $location_id ] : null;

    if ( ! $site ) {
        return array( 'name' => 'N/A', 'address' => 'N/A' );
    }

    $name = isset( $site->name ) ? (string) $site->name : 'N/A';

    // Match admin Locations address formatting in admin_locations_page().
    $address = trim(
        ( $site->address ?? '' ) .
        ( ! empty( $site->address ) && ! empty( $site->city ) ? ', ' : '' ) .
        ( $site->city ?? '' ) .
        ( ! empty( $site->city ) && ! empty( $site->zip_code ) ? ' ' : '' ) .
        ( $site->zip_code ?? '' )
    );
    if ( $address === '' ) {
        $address = 'Address Not Provided';
    }

    return array( 'name' => $name, 'address' => $address );
}
    
// Insert a local edit log entry into the DB.
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
    // Render the Timesheets admin page template.
    // (Template will use variables/functions available in this scope or via plugin includes.)
    include WIW_PLUGIN_PATH . 'admin/timesheets-page.php';
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
// Only overwrite clock_in/clock_out if we have valid new times
// Otherwise keep existing DB values (N/A stays N/A)
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

public function ajax_client_update_entry() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 403 );
	}

	check_ajax_referer( 'wiw_local_edit_entry', 'security' );

	global $wpdb;

	$entry_id       = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
	$clock_in_time  = isset( $_POST['clock_in_time'] ) ? sanitize_text_field( wp_unslash( $_POST['clock_in_time'] ) ) : '';
	$clock_out_time = isset( $_POST['clock_out_time'] ) ? sanitize_text_field( wp_unslash( $_POST['clock_out_time'] ) ) : '';
	$break_minutes  = isset( $_POST['break_minutes'] ) ? absint( $_POST['break_minutes'] ) : 0;

	if ( ! $entry_id ) {
		wp_send_json_error( array( 'message' => 'Missing entry_id.' ), 400 );
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
		wp_send_json_error( array( 'message' => 'Entry not found.' ), 404 );
	}

	$timesheet_id = isset( $entry->timesheet_id ) ? absint( $entry->timesheet_id ) : 0;
	$date         = isset( $entry->date ) ? (string) $entry->date : '';

	if ( ! $timesheet_id ) {
		wp_send_json_error( array( 'message' => 'Entry is missing timesheet_id.' ), 400 );
	}
	if ( $date === '' ) {
		wp_send_json_error( array( 'message' => 'Entry is missing date.' ), 400 );
	}

	// Timezone
	$tz_string = get_option( 'timezone_string' );
	if ( empty( $tz_string ) ) {
		$tz_string = 'UTC';
	}
	$tz = new DateTimeZone( $tz_string );

	// Normalize input: allow blank (N/A). Only accept HH:MM when non-blank.
	$in_blank  = ( trim( $clock_in_time ) === '' );
	$out_blank = ( trim( $clock_out_time ) === '' );

	$in_valid  = ( ! $in_blank && preg_match( '/^\d{2}:\d{2}$/', $clock_in_time ) );
	$out_valid = ( ! $out_blank && preg_match( '/^\d{2}:\d{2}$/', $clock_out_time ) );

	if ( ( ! $in_blank && ! $in_valid ) || ( ! $out_blank && ! $out_valid ) ) {
		wp_send_json_error( array( 'message' => 'Clock In/Out must be HH:MM (24-hour).' ), 400 );
	}

	// Build new datetime values (NULL when blank to preserve N/A).
	$new_clock_in  = null;
	$new_clock_out = null;

	if ( $in_valid ) {
		try {
			$new_clock_in = ( new DateTime( $date . ' ' . $clock_in_time . ':00', $tz ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Invalid Clock In value.' ), 400 );
		}
	}

	if ( $out_valid ) {
		try {
			$new_clock_out = ( new DateTime( $date . ' ' . $clock_out_time . ':00', $tz ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Invalid Clock Out value.' ), 400 );
		}
	}

	// If BOTH are provided, enforce ordering.
	if ( $in_valid && $out_valid ) {
		try {
			$dt_in  = new DateTime( $new_clock_in, $tz );
			$dt_out = new DateTime( $new_clock_out, $tz );
			if ( $dt_out <= $dt_in ) {
				wp_send_json_error( array( 'message' => 'Clock Out must be after Clock In.' ), 400 );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Invalid time entries.' ), 400 );
		}
	}

	// Compute hours only when BOTH times exist; otherwise 0.
	$clocked_hours  = 0.00;
	$payable_hours  = 0.00;

	if ( $in_valid && $out_valid ) {
		$dt_in  = new DateTime( $new_clock_in, $tz );
		$dt_out = new DateTime( $new_clock_out, $tz );

		$total_minutes = (int) round( ( $dt_out->getTimestamp() - $dt_in->getTimestamp() ) / 60 );

		if ( $break_minutes < 0 ) {
			$break_minutes = 0;
		}
		if ( $break_minutes > $total_minutes ) {
			$break_minutes = $total_minutes;
		}

		$clocked_minutes = max( 0, $total_minutes - $break_minutes );
		$clocked_hours   = round( $clocked_minutes / 60, 2 );

		// Payable hours: clamp to scheduled window if present.
		$payable_hours = $clocked_hours;

		try {
			$sched_start_raw = ! empty( $entry->scheduled_start ) ? (string) $entry->scheduled_start : '';
			$sched_end_raw   = ! empty( $entry->scheduled_end ) ? (string) $entry->scheduled_end : '';

			if ( $sched_start_raw !== '' || $sched_end_raw !== '' ) {
				$pay_in  = clone $dt_in;
				$pay_out = clone $dt_out;

				if ( $sched_start_raw !== '' ) {
					$sched_start_dt = new DateTime( $sched_start_raw, $tz );
					if ( $pay_in < $sched_start_dt ) {
						$pay_in = $sched_start_dt;
					}
				}
				if ( $sched_end_raw !== '' ) {
					$sched_end_dt = new DateTime( $sched_end_raw, $tz );
					if ( $pay_out > $sched_end_dt ) {
						$pay_out = $sched_end_dt;
					}
				}

				if ( $pay_out > $pay_in ) {
					$pay_total_minutes = (int) round( ( $pay_out->getTimestamp() - $pay_in->getTimestamp() ) / 60 );
					$pay_minutes       = max( 0, $pay_total_minutes - $break_minutes );
					$payable_hours     = round( $pay_minutes / 60, 2 );
				} else {
					$payable_hours = 0.00;
				}
			}
		} catch ( Exception $e ) {
			$payable_hours = $clocked_hours;
		}
	}

	// Update entry row:
	// - If blank => store NULL (N/A)
	// - If valid => store new datetime
	$update_data = array(
		'clock_in'      => $in_blank ? null : $new_clock_in,
		'clock_out'     => $out_blank ? null : $new_clock_out,
		'break_minutes' => (int) $break_minutes,
		'clocked_hours' => (float) $clocked_hours,
		'payable_hours' => (float) $payable_hours,
		'updated_at'    => current_time( 'mysql' ),
	);

    // --- LOGGING (match backend admin behavior) ---
$now          = current_time( 'mysql' );
$current_user = wp_get_current_user();
$edited_by_login = (string) ( $current_user->user_login ?? '' );

// Load header (for employee/location/week fields in logs).
$header = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$table_headers} WHERE id = %d",
		$timesheet_id
	)
);

$employee_id     = (int) ( $header->employee_id ?? 0 );
$employee_name   = (string) ( $header->employee_name ?? '' );
$location_id     = (int) ( $header->location_id ?? ( $entry->location_id ?? 0 ) );
$location_name   = (string) ( $header->location_name ?? ( $entry->location_name ?? '' ) );
$week_start_date = (string) ( $header->week_start_date ?? '' );

// Old values (from DB)
$old_clock_in_raw  = (string) ( $entry->clock_in  ? $entry->clock_in  : '' );
$old_clock_out_raw = (string) ( $entry->clock_out ? $entry->clock_out : '' );

$old_clock_in_norm  = $this->normalize_datetime_to_minute( $old_clock_in_raw );
$old_clock_out_norm = $this->normalize_datetime_to_minute( $old_clock_out_raw );

// New values (what we are about to store)
$new_clock_in_raw  = isset( $update_data['clock_in'] )  ? (string) $update_data['clock_in']  : $old_clock_in_raw;
$new_clock_out_raw = isset( $update_data['clock_out'] ) ? (string) $update_data['clock_out'] : $old_clock_out_raw;

$new_clock_in_norm  = $this->normalize_datetime_to_minute( $new_clock_in_raw );
$new_clock_out_norm = $this->normalize_datetime_to_minute( $new_clock_out_raw );

$old_break = (int) $entry->break_minutes;
$new_break = (int) $break_minutes;

if ( $old_clock_in_norm !== $new_clock_in_norm ) {
	$this->insert_local_edit_log( array(
		'timesheet_id'           => $timesheet_id,
		'entry_id'               => $entry_id,
		'wiw_time_id'            => (int) ( $entry->wiw_time_id ?? 0 ),
		'edit_type'              => 'Clock in',
		'old_value'              => $old_clock_in_norm,
		'new_value'              => $new_clock_in_norm,
		'edited_by_user_id'      => get_current_user_id(),
		'edited_by_user_login'   => $edited_by_login,
		'edited_by_display_name' => (string) ( $current_user->display_name ?? '' ),
		'employee_id'            => $employee_id,
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
		'wiw_time_id'            => (int) ( $entry->wiw_time_id ?? 0 ),
		'edit_type'              => 'Clock out',
		'old_value'              => $old_clock_out_norm,
		'new_value'              => $new_clock_out_norm,
		'edited_by_user_id'      => get_current_user_id(),
		'edited_by_user_login'   => $edited_by_login,
		'edited_by_display_name' => (string) ( $current_user->display_name ?? '' ),
		'employee_id'            => $employee_id,
		'employee_name'          => $employee_name,
		'location_id'            => $location_id,
		'location_name'          => $location_name,
		'week_start_date'        => $week_start_date,
		'created_at'             => $now,
	) );
}

if ( $old_break !== $new_break ) {
	$this->insert_local_edit_log( array(
		'timesheet_id'           => $timesheet_id,
		'entry_id'               => $entry_id,
		'wiw_time_id'            => (int) ( $entry->wiw_time_id ?? 0 ),
		'edit_type'              => 'Break Mins',
		'old_value'              => (string) $old_break,
		'new_value'              => (string) $new_break,
		'edited_by_user_id'      => get_current_user_id(),
		'edited_by_user_login'   => $edited_by_login,
		'edited_by_display_name' => (string) ( $current_user->display_name ?? '' ),
		'employee_id'            => $employee_id,
		'employee_name'          => $employee_name,
		'location_id'            => $location_id,
		'location_name'          => $location_name,
		'week_start_date'        => $week_start_date,
		'created_at'             => $now,
	) );
}

	$updated = $wpdb->update(
		$table_entries,
		$update_data,
		array( 'id' => $entry_id ),
		array( '%s', '%s', '%d', '%f', '%f', '%s' ),
		array( '%d' )
	);

	if ( $updated === false ) {
		wp_send_json_error( array( 'message' => 'Database update failed.' ), 500 );
	}

	// ✅ IMPORTANT: Recalculate flags after any client edit so flags resolve/reactivate correctly
	try {
		$clock_in_str  = ! empty( $update_data['clock_in'] ) ? (string) $update_data['clock_in'] : (string) ( $entry->clock_in ?? '' );
		$clock_out_str = ! empty( $update_data['clock_out'] ) ? (string) $update_data['clock_out'] : (string) ( $entry->clock_out ?? '' );

		$sched_start_local = ! empty( $entry->scheduled_start ) ? (string) $entry->scheduled_start : '';
		$sched_end_local   = ! empty( $entry->scheduled_end ) ? (string) $entry->scheduled_end : '';

		$this->wiwts_sync_store_time_flags(
			(int) ( $entry->wiw_time_id ?? 0 ),
			(string) $clock_in_str,
			(string) $clock_out_str,
			(string) $sched_start_local,
			(string) $sched_end_local,
			$tz
		);
	} catch ( Exception $e ) {
		// Do not fail the edit if flags calculation fails
	}

	// Keep header totals in sync (sum stored clocked_hours).
	$total_clocked = (float) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COALESCE(SUM(clocked_hours), 0) FROM {$table_entries} WHERE timesheet_id = %d",
			$timesheet_id
		)
	);

	$wpdb->update(
		$table_headers,
		array(
			'total_clocked_hours' => (float) round( $total_clocked, 2 ),
			'updated_at'          => current_time( 'mysql' ),
		),
		array( 'id' => $timesheet_id ),
		array( '%f', '%s' ),
		array( '%d' )
	);

	wp_send_json_success(
		array(
			'message'             => 'Saved.',
			'total_clocked_hours' => (float) round( $total_clocked, 2 ),
		)
	);
}

    /**
     * Legacy/compat approve handler.
     *
     * Some older JS/actions still call:
     *  - wiw_approve_single_timesheet
     *  - wiw_approve_timesheet
     *  - wiw_approve_timesheet_period
     *
     * We approve locally only (DB status update), so route to the local approver.
     */
    public function handle_approve_timesheet() {
        // Delegate to the local single-entry approve endpoint.
        // This expects: action=wiw_local_approve_entry and nonce=wiw_local_approve_entry
        // BUT the DB-update logic is correct here, so we reuse it.
        $this->ajax_local_approve_entry();
    }

// AJAX handler: Preview reset of a timesheet entry from WIW API data (no DB writes).
public function ajax_client_reset_entry_from_api() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
	}

	// Manual nonce check so we ALWAYS return JSON (never plain -1).
	$nonce = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'wiw_client_reset_entry_from_api' ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
	}

	global $wpdb;

	$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
	if ( ! $entry_id ) {
		wp_send_json_error( array( 'message' => 'Missing entry_id.' ), 400 );
	}

	$table_entries = $wpdb->prefix . 'wiw_timesheet_entries';

	$entry = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_entries} WHERE id = %d",
			$entry_id
		)
	);

	if ( ! $entry ) {
		wp_send_json_error( array( 'message' => 'Entry not found.' ), 404 );
	}

	$wiw_time_id = isset( $entry->wiw_time_id ) ? absint( $entry->wiw_time_id ) : 0;
	if ( ! $wiw_time_id ) {
		wp_send_json_error( array( 'message' => 'This entry is missing wiw_time_id, cannot preview reset.' ), 400 );
	}

	// Timezone (WP setting)
	$tz_string = get_option( 'timezone_string' );
	if ( empty( $tz_string ) ) {
		$tz_string = 'UTC';
	}
	$tz = new DateTimeZone( $tz_string );

	// Helper to format datetime strings into "07:50 am" (or N/A).
	$format_time = function( $datetime_str ) use ( $tz ) {
		$datetime_str = is_scalar( $datetime_str ) ? trim( (string) $datetime_str ) : '';
		if ( $datetime_str === '' ) {
			return 'N/A';
		}
		try {
			$dt = new DateTime( $datetime_str );
			$dt->setTimezone( $tz );
			return strtolower( $dt->format( 'g:i a' ) );
		} catch ( Exception $e ) {
			return 'N/A';
		}
	};

	// Current local DB values (preview only)
	$current_clock_in  = ! empty( $entry->clock_in ) ? (string) $entry->clock_in : '';
	$current_clock_out = ! empty( $entry->clock_out ) ? (string) $entry->clock_out : '';
	$current_break     = isset( $entry->break_minutes ) ? (int) $entry->break_minutes : 0;

	// Fetch authoritative WIW time record (preview only)
	$endpoint = "times/{$wiw_time_id}";
	$result   = WIW_API_Client::request( $endpoint, array(), WIW_API_Client::METHOD_GET );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array( 'message' => 'WIW API request failed: ' . $result->get_error_message() ),
			500
		);
	}

	$time_obj = null;
	if ( isset( $result->time ) ) {
		$time_obj = $result->time;
	} elseif ( is_object( $result ) ) {
		$time_obj = $result;
	}

	$api_start = '';
	$api_end   = '';

	if ( $time_obj ) {
		$api_start = isset( $time_obj->start_time ) ? (string) $time_obj->start_time : '';
		$api_end   = isset( $time_obj->end_time ) ? (string) $time_obj->end_time : '';
	}

// Prepare preview values (no DB writes yet)
// If apply_reset=1, write the WIW values to DB and recalc totals (no logs/flags yet).
$apply_reset = isset( $_POST['apply_reset'] ) ? absint( $_POST['apply_reset'] ) : 0;

if ( $apply_reset === 1 ) {
	// Convert WIW API start/end into local datetime strings (or NULL if empty).
	$new_clock_in_db  = null;
	$new_clock_out_db = null;

	if ( $api_start !== '' ) {
		try {
			$dt_in = new DateTime( $api_start, new DateTimeZone( 'UTC' ) );
			$dt_in->setTimezone( $tz );
			$new_clock_in_db = $dt_in->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			$new_clock_in_db = null;
		}
	}

	if ( $api_end !== '' ) {
		try {
			$dt_out = new DateTime( $api_end, new DateTimeZone( 'UTC' ) );
			$dt_out->setTimezone( $tz );
			$new_clock_out_db = $dt_out->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			$new_clock_out_db = null;
		}
	}

	// Recompute clocked/payable hours only if both times exist and are ordered.
	$break_minutes = isset( $entry->break_minutes ) ? (int) $entry->break_minutes : 0;
	$clocked_hours = 0.00;
	$payable_hours = 0.00;

	if ( $new_clock_in_db && $new_clock_out_db ) {
		try {
			$dt_in  = new DateTime( $new_clock_in_db, $tz );
			$dt_out = new DateTime( $new_clock_out_db, $tz );

			if ( $dt_out > $dt_in ) {
				$total_minutes = (int) round( ( $dt_out->getTimestamp() - $dt_in->getTimestamp() ) / 60 );

				if ( $break_minutes < 0 ) {
					$break_minutes = 0;
				}
				if ( $break_minutes > $total_minutes ) {
					$break_minutes = $total_minutes;
				}

				$clocked_minutes = max( 0, $total_minutes - $break_minutes );
				$clocked_hours   = round( $clocked_minutes / 60, 2 );

				// Payable hours clamp to scheduled window if present.
				$payable_hours = $clocked_hours;

				$sched_start_raw = ! empty( $entry->scheduled_start ) ? (string) $entry->scheduled_start : '';
				$sched_end_raw   = ! empty( $entry->scheduled_end ) ? (string) $entry->scheduled_end : '';

				if ( $sched_start_raw !== '' || $sched_end_raw !== '' ) {
					$pay_in  = clone $dt_in;
					$pay_out = clone $dt_out;

					if ( $sched_start_raw !== '' ) {
						$sched_start_dt = new DateTime( $sched_start_raw, $tz );
						if ( $pay_in < $sched_start_dt ) {
							$pay_in = $sched_start_dt;
						}
					}
					if ( $sched_end_raw !== '' ) {
						$sched_end_dt = new DateTime( $sched_end_raw, $tz );
						if ( $pay_out > $sched_end_dt ) {
							$pay_out = $sched_end_dt;
						}
					}

					if ( $pay_out > $pay_in ) {
						$pay_total_minutes = (int) round( ( $pay_out->getTimestamp() - $pay_in->getTimestamp() ) / 60 );
						$pay_minutes       = max( 0, $pay_total_minutes - $break_minutes );
						$payable_hours     = round( $pay_minutes / 60, 2 );
					} else {
						$payable_hours = 0.00;
					}
				}
			}
		} catch ( Exception $e ) {
			$clocked_hours = 0.00;
			$payable_hours = 0.00;
		}
	}

// --- LOG DIFFS WITH (Reset) (same pattern as backend) ---
$table_logs    = $wpdb->prefix . 'wiw_timesheet_edit_logs';
$table_headers = $wpdb->prefix . 'wiw_timesheets';

$header = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$table_headers} WHERE id = %d",
		(int) ( $entry->timesheet_id ?? 0 )
	)
);

$current_user = wp_get_current_user();
$now          = current_time( 'mysql' );

// Normalize to minute (Y-m-d H:i) or blank.
$to_minute = function( $dt_str ) {
	$dt_str = is_scalar( $dt_str ) ? trim( (string) $dt_str ) : '';
	if ( $dt_str === '' ) {
		return '';
	}
	try {
		$dt = new DateTime( $dt_str );
		return $dt->format( 'Y-m-d H:i' );
	} catch ( Exception $e ) {
		return '';
	}
};

// Old values (from DB before reset)
$old_clock_in  = $to_minute( ! empty( $entry->clock_in ) ? (string) $entry->clock_in : '' );
$old_clock_out = $to_minute( ! empty( $entry->clock_out ) ? (string) $entry->clock_out : '' );
$old_break     = (string) (int) ( $entry->break_minutes ?? 0 );

// New values (what reset is applying)
$new_clock_in  = $to_minute( $new_clock_in_db ? (string) $new_clock_in_db : '' );
$new_clock_out = $to_minute( $new_clock_out_db ? (string) $new_clock_out_db : '' );
$new_break     = (string) (int) ( $entry->break_minutes ?? 0 ); // reset currently keeps break the same

$changes = array(
	'Clock in (Reset)'   => array( $old_clock_in,  $new_clock_in ),
	'Clock out (Reset)'  => array( $old_clock_out, $new_clock_out ),
	'Break Mins (Reset)' => array( $old_break,     $new_break ),
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
			'timesheet_id'           => (int) ( $entry->timesheet_id ?? 0 ),
			'entry_id'               => 0,
			'wiw_time_id'            => (int) ( $entry->wiw_time_id ?? 0 ),
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
		),
		array(
			'%d','%d','%d','%s','%s','%s','%d','%s','%s','%d','%s','%d','%s','%s','%s'
		)
	);
}
// --- END LOGGING ---

	// Update entry row.
	$updated = $wpdb->update(
		$table_entries,
		array(
			'clock_in'      => $new_clock_in_db,
			'clock_out'     => $new_clock_out_db,
			'clocked_hours' => (float) $clocked_hours,
			'payable_hours' => (float) $payable_hours,
			'updated_at'    => current_time( 'mysql' ),
		),
		array( 'id' => $entry_id ),
		array( '%s', '%s', '%f', '%f', '%s' ),
		array( '%d' )
	);

	if ( $updated === false ) {
		wp_send_json_error( array( 'message' => 'Reset DB update failed.' ), 500 );
	}

	// Recalc header totals (clocked hours sum).
	$table_headers = $wpdb->prefix . 'wiw_timesheets';
	$total_clocked = (float) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COALESCE(SUM(clocked_hours), 0) FROM {$table_entries} WHERE timesheet_id = %d",
			(int) ( $entry->timesheet_id ?? 0 )
		)
	);

	$wpdb->update(
		$table_headers,
		array(
			'total_clocked_hours' => (float) round( $total_clocked, 2 ),
			'updated_at'          => current_time( 'mysql' ),
		),
		array( 'id' => (int) ( $entry->timesheet_id ?? 0 ) ),
		array( '%f', '%s' ),
		array( '%d' )
	);

	wp_send_json_success(
		array(
			'message' => 'Reset applied.',
			'preview' => array(
				'current' => array(
					'clock_in'      => $format_time( $current_clock_in ),
					'clock_out'     => $format_time( $current_clock_out ),
					'break_minutes' => (int) $current_break,
				),
				'api' => array(
					'clock_in'      => $format_time( $api_start ),
					'clock_out'     => $format_time( $api_end ),
					'break_minutes' => (int) $current_break,
				),
			),
			'total_clocked_hours' => (float) round( $total_clocked, 2 ),
		)
	);
}

// Default: preview only (no DB writes)
wp_send_json_success(
	array(
		'message' => 'Reset preview loaded.',
		'preview' => array(
			'current' => array(
				'clock_in'      => $format_time( $current_clock_in ),
				'clock_out'     => $format_time( $current_clock_out ),
				'break_minutes' => (int) $current_break,
			),
			'api' => array(
				'clock_in'      => $format_time( $api_start ),
				'clock_out'     => $format_time( $api_end ),
				'break_minutes' => (int) $current_break,
			),
		),
	)
);

}

/*
 * AJAX handler: Approve a single timesheet entry (local only).
 * - Updates wp_wiw_timesheet_entries.status from pending -> approved
 * - Writes a log entry using the ENTRY ID
 */
public function ajax_local_approve_entry() {

    // Capability (local-only approve)
    // Admins always allowed. Clients allowed if they have a client account number on their profile.
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        $current_user_id = get_current_user_id();
        $client_id_raw   = get_user_meta( $current_user_id, 'client_account_number', true );
        $client_id       = is_scalar( $client_id_raw ) ? trim( (string) $client_id_raw ) : '';

        if ( $client_id === '' ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
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
