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
            // Fetch the Client ID using the specific meta key
            $client_id = get_user_meta( get_current_user_id(), 'client_account_number', true );
            
            if ( ! $client_id ) {
                return [];
            }
            
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
 * Handle Front-end Portal Actions (Approval)
 */
add_action( 'template_redirect', 'wiw_handle_portal_actions' );

if ( ! function_exists( 'wiw_handle_portal_actions' ) ) {
    function wiw_handle_portal_actions() {
        // Only trigger if our specific action is sent via POST
        if ( ! isset( $_POST['wiw_action'] ) || $_POST['wiw_action'] !== 'approve_timesheet' ) {
            return;
        }

        $timesheet_id = intval( $_POST['timesheet_id'] );

        // 1. Verify Security (Nonce)
        if ( ! isset( $_POST['wiw_nonce'] ) || ! wp_verify_nonce( $_POST['wiw_nonce'], 'wiw_approve_' . $timesheet_id ) ) {
            wp_die( 'Security check failed. Please refresh and try again.' );
        }

        // 2. Permission & Scoping Check
        if ( ! is_user_logged_in() ) return;

        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_wiw_timesheets'; // Based on your confirmed table name
        $client_id = get_user_meta( get_current_user_id(), 'client_account_number', true );

        // SECURE CHECK: Ensure the user actually owns this record
        $is_allowed = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE id = %d AND location_id = %s",
            $timesheet_id,
            $client_id
        ) );

        if ( $is_allowed || current_user_can( 'manage_options' ) ) {
            $wpdb->update(
                $table_name,
                array( 'status' => 'approved' ),
                array( 'id' => $timesheet_id )
            );

            // Redirect back with a success message in the URL
            wp_redirect( add_query_arg( 'wiw_msg', 'approved', wp_get_referer() ) );
            exit;
        }
    }
}
