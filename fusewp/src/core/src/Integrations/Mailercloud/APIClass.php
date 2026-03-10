<?php

namespace FuseWP\Core\Integrations\Mailercloud;

class APIClass
{
    protected $api_key;

    protected $api_base_url = 'https://cloudapi.mailercloud.com/v1';

    public function __construct($api_key)
    {
        $this->api_key = $api_key;
    }

    /**
     * Make HTTP request to Mailercloud API
     *
     * @param string $endpoint
     * @param array $args
     * @param string $method
     *
     * @return array
     * @throws \Exception
     */
    public function make_request($endpoint, $args = [], $method = 'get')
    {
        $url = $this->api_base_url . '/' . ltrim($endpoint, '/');

        $wp_args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => $this->api_key,
                'Content-Type'  => 'application/json',
            ]
        ];

        if (in_array(strtolower($method), ['post', 'put', 'patch']) && ! empty($args)) {
            $wp_args['body'] = json_encode($args);
        }

        if (strtolower($method) == 'get' && ! empty($args)) {
            $url = add_query_arg($args, $url);
        }

        $response = wp_remote_request($url, $wp_args);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $response_http_code = wp_remote_retrieve_response_code($response);

        $response_body = wp_remote_retrieve_body($response);

        if ( ! fusewp_is_http_code_success($response_http_code)) {
            throw new \Exception($response_body, $response_http_code);
        }

        $response_body = json_decode($response_body);

        return ['status' => $response_http_code, 'body' => $response_body];
    }

    /**
     * @throws \Exception
     */
    public function post($endpoint, $args = [])
    {
        return $this->make_request($endpoint, $args, 'post');
    }

    /**
     * @throws \Exception
     */
    public function get($endpoint, $args = [])
    {
        return $this->make_request($endpoint, $args);
    }

    /**
     * @throws \Exception
     */
    public function put($endpoint, $args = [])
    {
        return $this->make_request($endpoint, $args, 'put');
    }

    /**
     * @throws \Exception
     */
    public function delete($endpoint, $args = [])
    {
        return $this->make_request($endpoint, $args, 'delete');
    }
}
