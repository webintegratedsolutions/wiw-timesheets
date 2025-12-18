<?php
/**
 * Shared Data Functions for WIW Timesheets
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wiw_get_timesheets' ) ) {
    function wiw_get_timesheets() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wiw_timesheets'; 
        $client_id = get_user_meta( get_current_user_id(), 'client_account_number', true );

        if ( ! current_user_can( 'manage_options' ) ) {
            if ( ! $client_id ) return [];
            $query = $wpdb->prepare( "SELECT * FROM $table_name WHERE location_id = %s ORDER BY date DESC", $client_id );
        } else {
            $query = "SELECT * FROM $table_name ORDER BY date DESC";
        }

        return $wpdb->get_results( $query );
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