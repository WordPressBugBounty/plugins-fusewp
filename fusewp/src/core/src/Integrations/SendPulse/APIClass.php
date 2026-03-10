<?php

namespace FuseWP\Core\Integrations\SendPulse;

class APIClass
{
    protected $api_url;
    protected $client_id;
    protected $client_secret;
    protected $access_token;

    /**
     * @var string
     */
    protected $api_base_url = 'https://api.sendpulse.com';

    public function __construct($client_id, $client_secret)
    {
        $this->client_id     = $client_id;
        $this->client_secret = $client_secret;
        $this->api_url       = $this->api_base_url;
    }

    /**
     * Option key for storing access token and expiration
     */
    const TOKEN_OPTION_KEY = 'fusewp_sendpulse_access_token';

    /**
     * Get or refresh access token
     * Token expires in 1 hour (3600 seconds)
     * 
     * @return string
     * @throws \Exception
     */
    protected function get_access_token()
    {
        $token_data = get_option(self::TOKEN_OPTION_KEY, []);

        // Check if we have a valid token that hasn't expired (with 60 second safety margin)
        if ( ! empty($token_data['token']) && ! empty($token_data['expires']) && time() < ($token_data['expires'] - 60)) {
            return $token_data['token'];
        }

        // Request new token
        $url = $this->api_url . '/oauth/access_token';

        $wp_args = [
            'method'  => 'POST',
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'FuseWP; ' . home_url(),
            ],
            'body'    => json_encode([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
            ]),
        ];

        $response = wp_remote_request($url, $wp_args);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_http_code = wp_remote_retrieve_response_code($response);

        if ( ! fusewp_is_http_code_success($response_http_code)) {
            throw new \Exception($response_body, $response_http_code);
        }

        $decoded_response = json_decode($response_body, true);

        if (empty($decoded_response['access_token'])) {
            throw new \Exception('Failed to obtain access token');
        }

        $access_token = $decoded_response['access_token'];
        $expires_in   = isset($decoded_response['expires_in']) ? intval($decoded_response['expires_in']) : 3600;
        $expires_at   = time() + ($expires_in - 60); // Subtract 60 seconds for safety margin

        update_option(self::TOKEN_OPTION_KEY, [
            'token'   => $access_token,
            'expires' => $expires_at,
        ]);

        return $access_token;
    }

    /**
     * @param string $endpoint
     * @param array $args
     * @param string $method
     *
     * @return array
     * @throws \Exception
     */
    public function make_request($endpoint = '', array $args = [], string $method = 'get')
    {
        $url = $this->api_url . '/' . ltrim($endpoint, '/');

        // Get access token
        $access_token = $this->get_access_token();

        $wp_args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'FuseWP; ' . home_url(),
            ],
        ];

        switch ($method) {
            case 'post':
            case 'put':
            case 'patch':
            case 'delete':
                if ( ! empty($args)) {
                    $wp_args['body'] = json_encode($args);
                }
                break;
            case 'get':
                if ( ! empty($args)) {
                    $url = add_query_arg($args, $url);
                }
                break;
        }

        $response = wp_remote_request($url, $wp_args);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_http_code = wp_remote_retrieve_response_code($response);

        // If unauthorized, try refreshing token once
        if ($response_http_code === 401) {
            // Clear stored token and retry
            delete_option(self::TOKEN_OPTION_KEY);
            $access_token = $this->get_access_token();
            $wp_args['headers']['Authorization'] = 'Bearer ' . $access_token;

            $response = wp_remote_request($url, $wp_args);

            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }

            $response_body = wp_remote_retrieve_body($response);
            $response_http_code = wp_remote_retrieve_response_code($response);
        }

        if ( ! fusewp_is_http_code_success($response_http_code)) {
            throw new \Exception($response_body, $response_http_code);
        }

        return ['status_code' => $response_http_code, 'body' => json_decode($response_body, true)];
    }

    /**
     * @param string $endpoint
     * @param array $args
     *
     * @return array
     * @throws \Exception
     */
    public function post($endpoint, $args = [])
    {
        return $this->make_request($endpoint, $args, 'post');
    }

    /**
     * @param string $endpoint
     * @param array $args
     *
     * @return array
     * @throws \Exception
     */
    public function put($endpoint, $args = [])
    {
        return $this->make_request($endpoint, $args, 'put');
    }

    /**
     * @param string $endpoint
     * @param array $args
     *
     * @return array
     * @throws \Exception
     */
    public function delete($endpoint, $args = [])
    {
        return $this->make_request($endpoint, $args, 'delete');
    }
}
