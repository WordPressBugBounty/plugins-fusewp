<?php

namespace FuseWP\Core\Integrations\Drip;

class APIClass
{
    protected $api_url;
    protected $api_key;
    protected $account_id;

    /**
     * @var string
     */
    protected $api_version = 'v2';

    /**
     * @var string
     */
    protected $api_base_url = 'https://api.getdrip.com/';

    public function __construct($account_id, $api_key)
    {
        $this->api_key = $api_key;
        $this->api_url = $this->api_base_url . $this->api_version . '/' . $account_id . '/';
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
            'Authorization' => sprintf('Basic %s:', base64_encode($this->api_key)),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'FuseWP',
        ];

        switch ($method) {
            case 'post':
            case 'put':
            case 'delete':
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
