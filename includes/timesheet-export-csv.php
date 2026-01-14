<?php
/**
 * Timesheet Export (CSV)
 *
 * Provides a GET download endpoint to export the currently filtered timesheet records.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Format decimal hours to match spreadsheet style:
 * - 28.00 => "28"
 * - 23.50 => "23.5"
 * - 23.75 => "23.75"
 */
function wiwts_export_format_hours_decimal( $val ) {
    if ( $val === null || $val === '' ) {
        return '';
    }
    $n = (float) $val;

    // Keep up to 2 decimals, strip trailing zeros and dot.
    $s = number_format( $n, 2, '.', '' );
    $s = rtrim( $s, '0' );
    $s = rtrim( $s, '.' );
    return $s;
}

/**
 * Format scheduled range like the template: "9:00 - 5:00" (no AM/PM).
 * Uses site timezone and assumes stored datetimes are already local.
 */
function wiwts_export_format_sched_range( $start_dt, $end_dt ) {
    $start_dt = is_scalar( $start_dt ) ? trim( (string) $start_dt ) : '';
    $end_dt   = is_scalar( $end_dt ) ? trim( (string) $end_dt ) : '';

    if ( $start_dt === '' || $end_dt === '' ) {
        return '';
    }

    try {
        $tz = wp_timezone();
        $ds = new DateTime( $start_dt, $tz );
        $de = new DateTime( $end_dt, $tz );

        // Template style appears like "9:00 - 5:00"
        $s = $ds->format( 'g:i' );
        $e = $de->format( 'g:i' );

        return $s . ' - ' . $e;
    } catch ( Exception $e ) {
        return '';
    }
}

/**
 * Build employee_id => position label map from When I Work employees data.
 * Uses the same mapping as the Employees admin page.
 *
 * If a user has no positions or positions is empty => "No Position"
 */
function wiwts_export_build_employee_position_map() {

    // Must match the Employees admin subpage mapping.
    $position_name_map = array(
        2611462 => 'ECA',
        2611465 => 'RECE',
    );

    $map = array();

    // Call WIW API directly (same endpoint/params as fetch_employees_data()).
    if ( ! class_exists( 'WIW_API_Client' ) || ! method_exists( 'WIW_API_Client', 'request' ) ) {
        return $map;
    }

    $employees_data = WIW_API_Client::request(
        'users',
        array(
            'include'           => 'positions,sites',
            'employment_status' => 1,
        ),
        WIW_API_Client::METHOD_GET
    );

    if ( is_wp_error( $employees_data ) ) {
        // Export still works; positions fall back to "No Position".
        return $map;
    }

    $users = isset( $employees_data->users ) ? $employees_data->users : array();
    if ( empty( $users ) || ! is_array( $users ) ) {
        return $map;
    }

    foreach ( $users as $user ) {
        $eid = isset( $user->id ) ? (string) $user->id : '';
        if ( $eid === '' ) {
            continue;
        }

        $positions = isset( $user->positions ) ? $user->positions : array();

        // Your rule: only one position, use positions[0].
        $pos_label = 'No Position';

        if ( is_array( $positions ) && ! empty( $positions ) ) {
            $first = $positions[0];
            $first_id = is_numeric( $first ) ? (int) $first : 0;

            if ( $first_id && isset( $position_name_map[ $first_id ] ) ) {
                $pos_label = $position_name_map[ $first_id ];
            } else {
                $pos_label = 'No Position';
            }
        }

        $map[ $eid ] = $pos_label;
    }

    return $map;
}

/**
 * Handle CSV export download.
 *
 * Endpoint:
 *   /wp-admin/admin-post.php?action=wiwts_export_csv&wiwts_export_nonce=...&wiw_status=...&wiw_emp=...&wiw_period=...
 */
function wiwts_export_csv_handle_download() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in to export.' );
    }

    // Nonce check (GET param).
    $nonce = isset( $_GET['wiwts_export_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['wiwts_export_nonce'] ) ) : '';
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wiwts_export_csv' ) ) {
        wp_die( 'Invalid export request.' );
    }

    $is_admin = current_user_can( 'manage_options' );

    // Client scoping: for non-admins, enforce location_id = client_account_number.
    $client_id = 0;
    if ( ! $is_admin ) {
        $current_user_id = get_current_user_id();
        $client_id_raw   = get_user_meta( $current_user_id, 'client_account_number', true );
        $client_id_str   = is_scalar( $client_id_raw ) ? trim( (string) $client_id_raw ) : '';
        $client_id       = absint( $client_id_str );

        if ( $client_id <= 0 ) {
            wp_die( 'No client account number found for export.' );
        }
    }

    // Filters (preserved from the UI).
    $filter_status = isset( $_GET['wiw_status'] ) ? sanitize_text_field( wp_unslash( $_GET['wiw_status'] ) ) : '';
    $filter_emp    = isset( $_GET['wiw_emp'] ) ? sanitize_text_field( wp_unslash( $_GET['wiw_emp'] ) ) : '';
    $filter_period = ( $is_admin && isset( $_GET['wiw_period'] ) ) ? sanitize_text_field( wp_unslash( $_GET['wiw_period'] ) ) : '';

    // Allowed statuses (mirror UI rules).
    $allowed_status = array( '', 'pending', 'approved', 'archived' );
    if ( $is_admin ) {
        $allowed_status[] = 'overdue';
    }
    if ( ! in_array( $filter_status, $allowed_status, true ) ) {
        $filter_status = $is_admin ? 'overdue' : 'pending';
    }

    // Period filter (admin-only) format: "YYYY-MM-DD|YYYY-MM-DD"
    $period_ws = '';
    $period_we = '';
    if ( $filter_period !== '' && strpos( $filter_period, '|' ) !== false ) {
        $parts = explode( '|', $filter_period );
        $period_ws = isset( $parts[0] ) ? trim( (string) $parts[0] ) : '';
        $period_we = isset( $parts[1] ) ? trim( (string) $parts[1] ) : '';
    }

    global $wpdb;

    $table_ts      = $wpdb->prefix . 'wiw_timesheets';
    $table_entries = $wpdb->prefix . 'wiw_timesheet_entries';

    // Build employee position map once (API) and fallback to "No Position".
    $pos_map = wiwts_export_build_employee_position_map();

    // === Build SQL for daily entry export (scoped + filtered) ===
    $where = array();
    $args  = array();

    // Join timesheets to get employee info.
    $sql = "
        SELECT
            e.*,
            ts.employee_id AS ts_employee_id,
            ts.employee_name AS ts_employee_name,
            ts.week_start_date AS ts_week_start_date,
            ts.week_end_date AS ts_week_end_date
        FROM {$table_entries} e
        INNER JOIN {$table_ts} ts ON ts.id = e.timesheet_id
    ";

    // Non-admin: enforce location scope.
    if ( ! $is_admin ) {
        $where[] = "e.location_id = %d";
        $args[]  = $client_id;
    }

    // Employee filter.
    if ( $filter_emp !== '' ) {
        $where[] = "ts.employee_id = %s";
        $args[]  = (string) $filter_emp;
    }

    // Period filter (admin-only).
    if ( $is_admin && $period_ws !== '' ) {
        $where[] = "ts.week_start_date = %s";
        $args[]  = $period_ws;

        // week_end_date can be NULL in schema; match exactly if provided.
        if ( $period_we !== '' ) {
            $where[] = "ts.week_end_date = %s";
            $args[]  = $period_we;
        }
    }

    // Status filter:
    // - overdue (admin): pending entries where date < approval-week cutoff
    // - otherwise: e.status match (or no constraint for All Records)
    if ( $is_admin && $filter_status === 'overdue' ) {

        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );

        $dow            = (int) $now->format( 'w' ); // 0=Sun .. 6=Sat
        $days_since_sat = ( $dow - 6 + 7 ) % 7;
        $last_sat       = $now->modify( '-' . $days_since_sat . ' days' )->setTime( 0, 0, 0 );
        $last_deadline  = $last_sat->modify( '+3 days' )->setTime( 8, 0, 0 );

        if ( $now < $last_deadline ) {
            $approval_week_end   = $last_sat;
            $approval_week_start = $approval_week_end->modify( '-6 days' );
        } else {
            $days_to_sat         = ( 6 - $dow + 7 ) % 7;
            $approval_week_end   = $now->modify( '+' . $days_to_sat . ' days' )->setTime( 0, 0, 0 );
            $approval_week_start = $approval_week_end->modify( '-6 days' );
        }

        $cutoff_ymd = $approval_week_start->format( 'Y-m-d' );

        $where[] = "e.status = %s";
        $args[]  = 'pending';

        $where[] = "e.date < %s";
        $args[]  = $cutoff_ymd;

    } elseif ( $filter_status !== '' ) {

        $where[] = "e.status = %s";
        $args[]  = $filter_status;

    } else {
        // All Records: no status constraint.
    }

    if ( ! empty( $where ) ) {
        $sql .= " WHERE " . implode( ' AND ', $where );
    }

    // Stable ordering: group by employee, then date, then entry id.
    $sql .= " ORDER BY ts.employee_name ASC, e.date ASC, e.id ASC";

    $prepared = ! empty( $args ) ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $rows     = $wpdb->get_results( $prepared );

    // === Stream CSV to browser ===
    $filename = 'timesheets-export-' . current_time( 'Y-m-d_His' ) . '.csv';

    if ( ob_get_length() ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    $out = fopen( 'php://output', 'w' );

    // Template headers (must mirror the provided spreadsheet exactly).
    fputcsv( $out, array(
        'Child Care',
        'ECA/RECE',
        'Hours',
        'Date',
        'Total no. of hours',
        'employee',
        'Time sheet',
        'Pay',
        'Invoiced',
        'ECA hours',
        'RECE hours',
    ) );

    if ( ! empty( $rows ) ) {
        $tz = wp_timezone();

        foreach ( $rows as $r ) {

            // Column A: Child Care (Location name)
            $location_name = isset( $r->location_name ) ? (string) $r->location_name : '';

            // Column B: ECA/RECE (positions[0] mapped) or No Position
            $eid = isset( $r->ts_employee_id ) ? (string) $r->ts_employee_id : '';
            $pos = ( $eid !== '' && isset( $pos_map[ $eid ] ) ) ? (string) $pos_map[ $eid ] : 'No Position';

            // Column C: Hours (Scheduled start/end)
            $hours_range = wiwts_export_format_sched_range(
                isset( $r->scheduled_start ) ? $r->scheduled_start : '',
                isset( $r->scheduled_end ) ? $r->scheduled_end : ''
            );

            // Column D: Date (single day; if shift spans midnight, we still use the start date = stored e.date)
            $date_ymd = isset( $r->date ) ? (string) $r->date : '';
            $date_out = '';
            if ( $date_ymd !== '' ) {
                $ts = strtotime( $date_ymd . ' 00:00:00' );
                if ( $ts ) {
                    // Matches the template style like "Jan 2"
                    $date_out = wp_date( 'M j', $ts, $tz );
                }
            }

            // Column E: Total no. of hours (scheduled_hours)
            $sched_hours = wiwts_export_format_hours_decimal( isset( $r->scheduled_hours ) ? $r->scheduled_hours : '' );

            // Column F: employee name
            $emp_name = isset( $r->ts_employee_name ) ? (string) $r->ts_employee_name : '';

            // Column G: Time sheet => always Yes
            $timesheet_yes = 'Yes';

            // Column H/I blank
            $pay_blank      = '';
            $invoiced_blank = '';

            // Column J/K conditional
            $eca_hours  = '0';
            $rece_hours = '0';

            if ( $pos === 'ECA' ) {
                $eca_hours = $sched_hours;
            } elseif ( $pos === 'RECE' ) {
                $rece_hours = $sched_hours;
            } else {
                // No Position => both blank
            }

            fputcsv( $out, array(
                $location_name,   // A
                $pos,             // B
                $hours_range,     // C
                $date_out,        // D
                $sched_hours,     // E
                $emp_name,        // F
                $timesheet_yes,   // G
                $pay_blank,       // H
                $invoiced_blank,  // I
                $eca_hours,       // J
                $rece_hours,      // K
            ) );
        }
    }

    fclose( $out );
    exit;
}

add_action( 'admin_post_wiwts_export_csv', 'wiwts_export_csv_handle_download' );
