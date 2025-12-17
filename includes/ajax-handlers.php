<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AJAX handlers for WIW Timesheets.
 */

/**
 * Edit total timesheet hours.
 */
function wiwts_ajax_edit_timesheet_hours() {
    global $wpdb;

    check_ajax_referer( 'wiwts_edit_timesheet_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }

    $timesheet_id = (int) ( $_POST['timesheet_id'] ?? 0 );
    $hours        = (float) ( $_POST['hours'] ?? 0 );

    if ( ! $timesheet_id ) {
        wp_send_json_error( 'Invalid timesheet ID.' );
    }

    $table = $wpdb->prefix . 'wiw_timesheets';

    $updated = $wpdb->update(
        $table,
        [
            'total_clocked_hours' => round( $hours, 2 ),
            'updated_at'          => current_time( 'mysql' ),
        ],
        [ 'id' => $timesheet_id ]
    );

    if ( $updated === false ) {
        wp_send_json_error( 'Database update failed.' );
    }

    wp_send_json_success();
}

/**
 * Update a local timesheet entry.
 */
function wiwts_ajax_local_update_entry() {
    global $wpdb;

    check_ajax_referer( 'wiwts_edit_entry_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }

    $entry_id      = (int) ( $_POST['entry_id'] ?? 0 );
    $clock_in      = sanitize_text_field( $_POST['clock_in'] ?? '' );
    $clock_out     = sanitize_text_field( $_POST['clock_out'] ?? '' );
    $break_minutes = (int) ( $_POST['break_minutes'] ?? 0 );

    if ( ! $entry_id ) {
        wp_send_json_error( 'Invalid entry ID.' );
    }

    $table = $wpdb->prefix . 'wiw_timesheet_entries';

    $updated = $wpdb->update(
        $table,
        [
            'clock_in'      => $clock_in ?: null,
            'clock_out'     => $clock_out ?: null,
            'break_minutes' => $break_minutes,
            'updated_at'    => current_time( 'mysql' ),
        ],
        [ 'id' => $entry_id ]
    );

    if ( $updated === false ) {
        wp_send_json_error( 'Database update failed.' );
    }

    wp_send_json_success();
}
