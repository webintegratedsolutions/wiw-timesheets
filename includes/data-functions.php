<?php
/**
 * Fetch timesheets with automatic security scoping
 */
function wiw_get_timesheets($args = []) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wiw_timesheets'; // Adjust to your actual table name

    $defaults = [
        'location_id' => null,
        'status'      => '',
        'orderby'     => 'id',
        'order'       => 'DESC'
    ];
    $params = wp_parse_args($args, $defaults);

    // --- SECURITY SCOPING ---
    // If the user is NOT an admin, force the location filter
    if (!current_user_can('manage_options')) {
        $user_location = get_user_meta(get_current_user_id(), 'wiw_assigned_location', true);
        
        // If a client has no location assigned, return empty to be safe
        if (!$user_location) return []; 
        
        $params['location_id'] = $user_location;
    }

    // Build the Query
    $query = "SELECT * FROM $table_name WHERE 1=1";
    if ($params['location_id']) {
        $query .= $wpdb->prepare(" AND location_id = %d", $params['location_id']);
    }
    
    $query .= " ORDER BY {$params['orderby']} {$params['order']}";

    return $wpdb->get_results($query);
}
