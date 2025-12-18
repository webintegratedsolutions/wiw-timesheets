<?php
/**
 * Client Portal Shortcode
 *
 * Shortcode:
 *   [wiw_client_portal]
 *
 * Mirrors the existing admin Local Timesheets UI on the front-end by dispatching
 * the exact hook suffix WordPress uses for the submenu page:
 *   wiw-timesheets_page_wiw-local-timesheets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIWTS_Client_Portal_Shortcode {

	private static $shortcode_present = false;

	/**
	 * Hard-resolved from WP debug output (no guesswork).
	 * Local Timesheets submenu:
	 *   parent: wiw-timesheets
	 *   slug:   wiw-local-timesheets
	 *   hook:   wiw-timesheets_page_wiw-local-timesheets
	 *
	 * @var string
	 */
	private static $hook_suffix = 'wiw-timesheets_page_wiw-local-timesheets';

	/**
	 * The submenu slug (also confirmed via debug output).
	 *
	 * @var string
	 */
	private static $menu_slug = 'wiw-local-timesheets';

	public static function init() {
		add_shortcode( 'wiw_client_portal', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_admin_assets' ), 20 );
	}

	public static function render( $atts = array(), $content = '' ) {
		self::$shortcode_present = true;

		if ( ! is_user_logged_in() ) {
			return '<p>You must be logged in to view this page.</p>';
		}

		$user_id   = get_current_user_id();
		$client_id = get_user_meta( $user_id, 'client_account_number', true );

		if ( empty( $client_id ) ) {
			return '<p>Client portal is not available: missing Client Account Number on your user profile.</p>';
		}

		// Make client_id visible to existing code that reads request vars.
		$_GET['client_id']     = (string) $client_id;
		$_REQUEST['client_id'] = (string) $client_id;

		// Mimic admin globals some renderers expect.
		$GLOBALS['pagenow']     = 'admin.php';
		$GLOBALS['plugin_page'] = self::$menu_slug;
		$_GET['page']           = self::$menu_slug;

		// Ensure admin menu hooks exist (plugin menu registration runs on admin_menu).
		self::ensure_admin_menu_loaded();

		ob_start();

		/**
		 * This is the key: dispatch the exact submenu hook suffix.
		 * Your add_submenu_page() callback is attached to this action.
		 */
		do_action( self::$hook_suffix );

		return ob_get_clean();
	}

	public static function maybe_enqueue_admin_assets() {
		if ( ! self::$shortcode_present ) {
			return;
		}

		self::ensure_admin_menu_loaded();

		/**
		 * Fire admin enqueue scripts for this exact hook suffix so your existing
		 * admin CSS/JS gets loaded on the front-end for this shortcode page.
		 */
		do_action( 'admin_enqueue_scripts', self::$hook_suffix );
	}

	private static function ensure_admin_menu_loaded() {
		// Ensure admin plugin functions are available.
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Ensure menus have been registered.
		if ( ! did_action( 'admin_menu' ) ) {
			do_action( 'admin_menu' );
		}
	}
}

WIWTS_Client_Portal_Shortcode::init();
