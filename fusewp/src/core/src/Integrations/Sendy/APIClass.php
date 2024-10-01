<?php

namespace FuseWP\Core\Integrations\Sendy;

class APIClass
{
    protected $installation_url;

    protected $api_key;

    public function __construct($installation_url, $api_key)
    {
        $this->api_key = $api_key;

        $this->installation_url = $installation_url;
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
        $url = trailingslashit($this->installation_url) . $endpoint;

        $wp_args = [
            'method'  => strtoupper($method),
            'timeout' => 30
        ];

        $wp_args['body'] = array_merge(['api_key' => $this->api_key], $args);

        $response = wp_remote_request($url, $wp_args);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $response_http_code = wp_remote_retrieve_response_code($response);

        return ['status' => $response_http_code, 'body' => wp_remote_retrieve_body($response)];
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