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
            $user_location = get_user_meta( get_current_user_id(), 'wiw_assigned_location', true );
            
            if ( ! $user_location ) {
                return [];
            }
            
            $query = $wpdb->prepare(
                "SELECT * FROM $table_name WHERE location_id = %d ORDER BY %s %s",
                $user_location,
                $params['orderby'],
                $params['order']
            );
        } else {
            $query = "SELECT * FROM $table_name ORDER BY {$params['orderby']} {$params['order']}";
        }

        return $wpdb->get_results( $query );
    }
}