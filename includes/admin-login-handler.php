<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin-post login handler for When I Work API.
 *
 * Isolated here to keep request-handling logic out of the main class file.
 */
/**
 * Handles the login request when the admin submits the login form.
 */
function wiwts_handle_wiw_login() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied.' );
    }

    if (
        ! isset( $_POST['wiw_login_nonce'] ) ||
        ! wp_verify_nonce( $_POST['wiw_login_nonce'], 'wiw_login_action' )
    ) {
        wp_die( 'Security check failed.' );
    }

    $api_key  = get_option( 'wiw_api_key' );
    $email    = get_option( 'wiw_login_email' );
    $password = get_option( 'wiw_login_password' );

    $login_result = WIW_API_Client::login( $api_key, $email, $password );

    $redirect_url = admin_url( 'admin.php?page=wiw-timesheets-settings' );

    if ( is_wp_error( $login_result ) ) {
        wp_safe_redirect(
            add_query_arg(
                'wiw_login_error',
                rawurlencode( $login_result->get_error_message() ),
                $redirect_url
            )
        );
        exit;
    }

    if ( ! isset( $login_result->login->token ) ) {
        wp_safe_redirect(
            add_query_arg(
                'wiw_login_error',
                rawurlencode( 'Login succeeded but no token was returned.' ),
                $redirect_url
            )
        );
        exit;
    }

    update_option( 'wiw_session_token', $login_result->login->token );

    wp_safe_redirect(
        add_query_arg( 'wiw_login_success', '1', $redirect_url )
    );
    exit;
}
