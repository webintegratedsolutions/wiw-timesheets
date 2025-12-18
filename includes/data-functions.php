<?php
/**
 * Shared Data Functions for WIW Timesheets
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wiw_get_timesheets' ) ) {
    function wiw_get_timesheets() {
        global $wpdb;
        
        $table_ts = $wpdb->prefix . 'wiw_timesheets'; 
        $table_daily = $wpdb->prefix . 'wiw_daily_records';

        $client_id = get_user_meta( get_current_user_id(), 'client_account_number', true );
        $is_admin = current_user_can( 'manage_options' );

        // If not admin and no client ID, return empty
        if ( ! $is_admin && ! $client_id ) return [];

        // This query selects the timesheet AND checks if any daily records are NOT approved
        // can_sign_off will be 1 if total records > 0 AND unapproved records == 0
        $sql = "
            SELECT ts.*, 
            (SELECT COUNT(*) FROM $table_daily WHERE timesheet_id = ts.id) as total_days,
            (SELECT COUNT(*) FROM $table_daily WHERE timesheet_id = ts.id AND status != 'approved') as unapproved_days
            FROM $table_ts as ts
        ";

        if ( ! $is_admin ) {
            $sql .= $wpdb->prepare( " WHERE ts.location_id = %s", $client_id );
        }

        $sql .= " ORDER BY ts.date DESC";

        return $wpdb->get_results( $sql );
    }
}

/**
 * Handle Front-end Portal Actions
 */
add_action( 'template_redirect', 'wiw_handle_portal_actions' );

if ( ! function_exists( 'wiw_handle_portal_actions' ) ) {
    function wiw_handle_portal_actions() {
        if ( ! isset( $_POST['wiw_action'] ) || $_POST['wiw_action'] !== 'approve_timesheet' ) return;
        
        $timesheet_id = intval( $_POST['timesheet_id'] );
        if ( ! wp_verify_nonce( $_POST['wiw_nonce'], 'wiw_approve_' . $timesheet_id ) ) wp_die( 'Security check failed.' );

        global $wpdb;
        $wpdb->update( 
            $wpdb->prefix . 'wiw_timesheets', 
            array( 'status' => 'approved' ), 
            array( 'id' => $timesheet_id ) 
        );

        wp_redirect( add_query_arg( 'wiw_msg', 'approved', wp_get_referer() ) );
        exit;
    }
}
