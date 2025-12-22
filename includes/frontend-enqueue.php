<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue front-end styles only when the client shortcode is present.
 */
function wiwts_enqueue_client_ui_assets() {
	if ( is_admin() ) {
		return;
	}

	// Only enqueue on singular content where shortcode is likely to exist.
	if ( ! is_singular() ) {
		return;
	}

	global $post;
	if ( ! $post || empty( $post->post_content ) ) {
		return;
	}

	if ( ! has_shortcode( $post->post_content, 'wiw_timesheets_client' ) ) {
		return;
	}

	$css_rel  = 'css/wiw-client-ui.css';
	$css_path = WIW_PLUGIN_PATH . $css_rel;
	$css_url  = WIW_PLUGIN_URL . $css_rel;

	$ver = file_exists( $css_path ) ? filemtime( $css_path ) : '1.0.0';

	wp_enqueue_style(
		'wiwts-client-ui',
		$css_url,
		array(),
		$ver
	);
}
add_action( 'wp_enqueue_scripts', 'wiwts_enqueue_client_ui_assets' );
