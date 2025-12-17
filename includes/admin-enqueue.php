<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Enqueue admin CSS for WIW Timesheets plugin.
 */
function wiwts_enqueue_admin_styles( $hook_suffix ) {
    // Only load on your plugin's main admin screen.
    if ( $hook_suffix !== 'toplevel_page_wiw-timesheets' ) {
        return;
    }

    wp_enqueue_style(
        'wiwts-admin-styles',
        WIW_PLUGIN_URL . 'css/wiw-admin.css',
        array(),
        '1.0.0'
    );
}

add_action( 'admin_enqueue_scripts', 'wiwts_enqueue_admin_styles' );
