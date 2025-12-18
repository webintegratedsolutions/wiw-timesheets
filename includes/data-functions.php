<?php
/**
 * Shared Data Functions for WIW Timesheets
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
            // ✅ We fetch the 'client_account_number' which maps to the 'site_id' (e.g., 1165986)
            $client_id = get_user_meta( get_current_user_id(), 'client_account_number', true );
            
            if ( ! $client_id ) {
                return [];
            }
            
            // We query the local table's location_id column using the Site ID from user meta
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
