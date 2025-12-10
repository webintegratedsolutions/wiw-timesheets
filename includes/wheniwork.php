<?php
// Exit if accessed directly (security)
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Client library for the When I Work scheduling and attendance platform.
 * Adapted to use WordPress HTTP API (wp_remote_request) instead of CURL.
 */
class Wheniwork
{
    const VERSION = '0.1';
    
    // HTTP Methods
    const METHOD_GET    = 'get';
    const METHOD_POST   = 'post';
    const METHOD_PUT    = 'put';
    const METHOD_PATCH  = 'patch';
    const METHOD_DELETE = 'delete';

    private $api_token;
    private $api_endpoint = 'https://api.wheniwork.com/2';

    // Instance constructor (not used for static login, but kept for future requests)
    function __construct($api_token = null, $options = [])
    {
        $this->api_token = $api_token;
    }

    /**
     * Performs the underlying HTTP request using WordPress's wp_remote_request.
     */
    private function makeRequest($method, $request, $params = [], $headers = [])
    {
        $url = $this->api_endpoint . '/' . $method;
        $body = null;

        // For GET or DELETE requests, parameters go in the URL
        if ( $params && ( $request == self::METHOD_GET || $request == self::METHOD_DELETE ) ) {
            $url = add_query_arg( $params, $url );
        }

        // For POST/PUT/PATCH requests, parameters go in the body
        if ( in_array( $request, [self::METHOD_POST, self::METHOD_PUT, self::METHOD_PATCH] ) ) {
            $body = json_encode( $params );
        }
        
        $final_headers = array_merge( 
            [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'WhenIWork-PHP/' . static::VERSION . ' (WordPress Plugin)',
            ],
            $headers // Custom headers (like W-Key for login, or W-Token for others)
        );

        // Add W-Token if it's set on the instance (not used for the static login call)
        if ($this->api_token && !isset($final_headers['W-Token'])) {
            $final_headers['W-Token'] = $this->api_token;
        }

        $args = [
            'method'    => strtoupper($request),
            'timeout'   => 30,
            'headers'   => $final_headers,
            'body'      => $body,
            'sslverify' => defined('WP_DEBUG') && WP_DEBUG ? false : true, // Only disable SSL verification if debugging is on
        ];

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body_content  = wp_remote_retrieve_body( $response );
        $data = json_decode( $body_content );

        if ( $response_code !== 200 && $response_code !== 201 ) {
            $error_message = isset( $data->error ) ? $data->error : 'Unknown API Error.';
            return new WP_Error( 'wiw_api_request_failed', "API Error ({$response_code}): " . $error_message );
        }

        return $data;
    }

    /**
     * Login helper using developer key and credentials to get back a login response
     *
     * @param string $key      Developer API key (the value saved in 'API Key' field)
     * @param string $email    Email of the user logging in
     * @param string $password Password of the user
     * @return object|WP_Error
     */
    public static function login($key, $email, $password)
    {
        // Data to send to the /login endpoint
        $params = [
            "username" => $email, // The API expects email as 'username'
            "password" => $password,
        ];

        // Headers, including the crucial W-Key
        $headers = [
            'W-Key' => $key
        ];

        // Use a temporary instance to make the request
        $login_instance = new static();
        $response = $login_instance->makeRequest("login", self::METHOD_POST, $params, $headers);

        return $response;
    }

    // Inside the Wheniwork class...

    /**
     * Executes an authenticated request to the When I Work API using the stored session token.
     * * @param string $endpoint The API method (e.g., '/times')
     * @param array $params Query parameters or POST body data.
     * @param string $method HTTP method (GET, POST, etc.)
     * @return object|WP_Error
     */
    public static function request($endpoint, $params = [], $method = self::METHOD_GET)
    {
        // 1. Retrieve the stored Session Token (W-Token)
        $token = get_option('wiw_session_token');

        if (empty($token)) {
            return new WP_Error('wiw_token_missing', 'The When I Work session token is missing. Please log in on the settings page.');
        }

        // 2. Set the W-Token header for the authenticated request
        $headers = [
            'W-Token' => $token
        ];

        // 3. Create a temporary instance and make the request
        $api_instance = new static();
        
        // Note: For Timesheet data, we usually use GET.
        $response = $api_instance->makeRequest($endpoint, $method, $params, $headers);

        return $response;
    }

    // You will need to add the `use` statement below 
    // to include the get_option function inside this file.
    // ...
}
