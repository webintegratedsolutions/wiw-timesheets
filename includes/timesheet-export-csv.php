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

    // Filters (preserved from the UI; used in later steps to fetch rows).
    $filter_status = isset( $_GET['wiw_status'] ) ? sanitize_text_field( wp_unslash( $_GET['wiw_status'] ) ) : '';
    $filter_emp    = isset( $_GET['wiw_emp'] ) ? sanitize_text_field( wp_unslash( $_GET['wiw_emp'] ) ) : '';
    $filter_period = isset( $_GET['wiw_period'] ) ? sanitize_text_field( wp_unslash( $_GET['wiw_period'] ) ) : '';

    // NOTE: Location is not an available filter by design. Client scoping will be enforced when we add data rows.
    // For this first safe step, we only output the template headers.

    $filename = 'timesheets-export-' . current_time( 'Y-m-d_His' ) . '.csv';

    // Ensure nothing has been sent before headers.
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

    fclose( $out );
    exit;
}

add_action( 'admin_post_wiwts_export_csv', 'wiwts_export_csv_handle_download' );
