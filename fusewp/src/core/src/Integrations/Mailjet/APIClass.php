<?php

namespace FuseWP\Core\Integrations\Mailjet;

class APIClass
{
    protected $api_url;
    protected $api_key;
    protected $secret_key;
    protected $api_version = 3;
    protected $api_base_url = 'https://api.mailjet.com/';

    public function __construct($api_key, $secret_key)
    {
        $this->api_key    = $api_key;
        $this->secret_key = $secret_key;
        $this->api_url    = $this->api_base_url . 'v' . $this->api_version . '/REST/';
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

        $wp_args = ['method' => strtoupper($method), 'timeout' => 30];

        $wp_args['headers'] = [
            'Authorization' => sprintf('Basic %s', base64_encode($this->api_key . ':' . $this->secret_key)),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'FuseWP',
        ];

        switch ($method) {
            case 'post':
            case 'put':
                $wp_args['body'] = json_encode($args);
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
