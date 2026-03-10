<?php

namespace FuseWP\Core\Integrations\BentoNow;

class APIClass
{
    protected $api_url;
    protected $publishable_key;
    protected $secret_key;
    protected $site_uuid;

    /**
     * @var string
     */
    protected $api_base_url = 'https://app.bentonow.com/api/v1';

    public function __construct($publishable_key, $secret_key, $site_uuid)
    {
        $this->publishable_key = $publishable_key;
        $this->secret_key      = $secret_key;
        $this->site_uuid       = $site_uuid;
        $this->api_url         = $this->api_base_url;
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

        // Always include site_uuid in query parameters
        $args['site_uuid'] = $this->site_uuid;

        $wp_args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->publishable_key . ':' . $this->secret_key),
                'User-Agent'    => 'FuseWP; ' . home_url(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        switch ($method) {
            case 'post':
            case 'put':
                if ( ! empty($args)) {
                    // Remove site_uuid from body, it's in query string
                    $body_args = $args;
                    unset($body_args['site_uuid']);
                    $wp_args['body'] = json_encode($body_args);
                }
                break;
            case 'get':
                // site_uuid stays in query string
                break;
            case 'delete':
                // DELETE requests typically don't have a body
                break;
        }

        // Add query parameters (including site_uuid)
        if ( ! empty($args) && in_array($method, ['get', 'delete'])) {
            $url = add_query_arg($args, $url);
        } elseif ($method === 'post' || $method === 'put') {
            // For POST/PUT, site_uuid goes in query string
            $url = add_query_arg(['site_uuid' => $this->site_uuid], $url);
        }

        $response = wp_remote_request($url, $wp_args);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_http_code = wp_remote_retrieve_response_code($response);

        // Check for API-level errors
        $decoded_response = json_decode($response_body, true);
        if (isset($decoded_response['error'])) {
            throw new \Exception($decoded_response['error'], $response_http_code);
        }

        if ( ! fusewp_is_http_code_success($response_http_code)) {
            throw new \Exception($response_body, $response_http_code);
        }

        return ['status_code' => $response_http_code, 'body' => $decoded_response];
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
}
