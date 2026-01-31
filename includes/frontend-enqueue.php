<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue front-end styles/scripts only when the client shortcode is present.
 */
function wiwts_enqueue_client_ui_assets() {
	if ( is_admin() ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
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

	if (
		! has_shortcode( $post->post_content, 'wiw_timesheets_client' )
		&& ! has_shortcode( $post->post_content, 'wiw_timesheets_client_filter' )
		&& ! has_shortcode( $post->post_content, 'wiw_timesheets_client_records' )
	) {
		return;
	}

	// CSS
	$css_rel  = 'css/wiw-client-ui.css';
	$css_path = WIW_PLUGIN_PATH . $css_rel;
	$css_url  = WIW_PLUGIN_URL . $css_rel;

	$css_ver = file_exists( $css_path ) ? filemtime( $css_path ) : '1.0.0';

	wp_enqueue_style(
		'wiwts-client-ui',
		$css_url,
		array(),
		$css_ver
	);

	$js_rel  = 'js/wiw-client-sync.js';
	$js_path = WIW_PLUGIN_PATH . $js_rel;
	$js_url  = WIW_PLUGIN_URL . $js_rel;
	$js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0';

	wp_enqueue_script(
		'wiwts-client-sync',
		$js_url,
		array(),
		$js_ver,
		true
	);

	$current_user_id = get_current_user_id();
	$client_id_raw   = get_user_meta( $current_user_id, 'client_account_number', true );
	$client_id       = is_scalar( $client_id_raw ) ? absint( $client_id_raw ) : 0;

	$rate_limit = (int) apply_filters( 'wiwts_frontend_sync_rate_limit_seconds', 900, $client_id, $current_user_id );
	$rate_limit = max( 30, $rate_limit );

	wp_localize_script(
		'wiwts-client-sync',
		'wiwtsClientSync',
		array(
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( 'wiwts_frontend_sync' ),
			'rateLimitSeconds'  => $rate_limit,
			'paths'             => array(
				trailingslashit(
					wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/'
				),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'wiwts_enqueue_client_ui_assets' );
