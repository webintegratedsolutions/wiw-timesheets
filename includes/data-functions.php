<?php
/**
 * Shared Data Functions for WIW Timesheets
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fetch timesheets with automatic location-based scoping.
 */
if ( ! function_exists( 'wiw_get_timesheets' ) ) {
    function wiw_get_timesheets( $args = [] ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wiw_timesheets'; 

        $defaults = [
            'orderby' => 'id',
            'order'   => 'DESC'
        ];
        $params = wp_parse_args( $args, $defaults );

        if ( ! current_user_can( 'manage_options' ) ) {
            $client_id = get_user_meta( get_current_user_id(), 'client_account_number', true );
            if ( ! $client_id ) return [];
            
            $query = $wpdb->prepare(
                "SELECT * FROM $table_name WHERE location_id = %s ORDER BY %s %s",
                $client_id,
                $params['orderby'],
                $params['order']
            );
        } else {
            $query = "SELECT * FROM $table_name ORDER BY {$params['orderby']} {$params['order']}";
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
        if ( ! isset( $_POST['wiw_action'] ) || $_POST['wiw_action'] !== 'approve_timesheet' ) {
            return;
        }

        $timesheet_id = intval( $_POST['timesheet_id'] );

        if ( ! isset( $_POST['wiw_nonce'] ) || ! wp_verify_nonce( $_POST['wiw_nonce'], 'wiw_approve_' . $timesheet_id ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! is_user_logged_in() ) return;

        global $wpdb;
        $table_timesheets = $wpdb->prefix . 'wiw_timesheets';
        $client_id = get_user_meta( get_current_user_id(), 'client_account_number', true );

        // 1. Ownership Check
        $timesheet = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_timesheets WHERE id = %d AND location_id = %s",
            $timesheet_id,
            $client_id
        ) );

        if ( ! $timesheet && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to approve this timesheet.' );
        }

        // 2. BUSINESS LOGIC CHECK: 
        // We should only proceed if the child records are ready.
        // For now, we will flag an error if the logic isn't met.
        // (In a future step, we can pull in your specific 'check_daily_records' function)
        
        $can_approve = true; // Temporary placeholder
        
        if ( $can_approve ) {
            $wpdb->update(
                $table_timesheets,
                array( 'status' => 'approved' ),
                array( 'id' => $timesheet_id )
            );
            wp_redirect( add_query_arg( 'wiw_msg', 'approved', wp_get_referer() ) );
        } else {
            wp_redirect( add_query_arg( 'wiw_msg', 'error_incomplete', wp_get_referer() ) );
        }
        exit;
    }
}
