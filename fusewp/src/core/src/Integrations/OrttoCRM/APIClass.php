<?php

namespace FuseWP\Core\Integrations\OrttoCRM;

class APIClass
{
    protected $api_key;

    protected $api_url;

    protected $base_url = 'https://api.ap3api.com/';

    protected $api_version = 1;

    public function __construct($api_key, $region = null)
    {
        // Set the base URL based on the region
        if ( ! empty($region)) {
            if (strtolower($region) == 'au') {
                $this->base_url = 'https://api.au.ap3api.com/';
            } elseif (strtolower($region) == 'eu') {
                $this->base_url = 'https://api.eu.ap3api.com/';
            }
        }

        $this->api_key = $api_key;
        $this->api_url = $this->base_url . 'v' . $this->api_version . '/';
    }

    /**
     * @param $endpoint
     * @param array $args
     * @param string $method
     *
     * @return array
     * @throws \Exception
     */
    public function make_request($endpoint, $args = [], $method = 'get')
    {
        $url = $this->api_url . $endpoint;

        $wp_args = [
            'method'     => strtoupper($method),
            'timeout'    => 30,
            'user-agent' => 'FuseWP; ' . home_url(),
            'headers'    => [
                'X-Api-Key'    => $this->api_key,
                'Content-Type' => 'application/json'
            ],
        ];

        switch (strtolower($method)) {
            case 'post':
            case 'put':
            case 'delete':
                $wp_args['headers']["Content-Type"] = "application/json";
                $wp_args['body']                    = json_encode($args);
                break;
            case 'get':
                $url = add_query_arg($args, $url);
                break;
        }

        $response = wp_remote_request($url, $wp_args);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $response_body = wp_remote_retrieve_body($response);

        $response_http_code = wp_remote_retrieve_response_code($response);

        if ( ! fusewp_is_http_code_success($response_http_code)) {
            throw new \Exception($response_body, $response_http_code);
        }

        return ['status_code' => $response_http_code, 'body' => json_decode($response_body, true)];
    }

    /**
     * @param $endpoint
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
