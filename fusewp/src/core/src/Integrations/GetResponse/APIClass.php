<?php

namespace FuseWP\Core\Integrations\GetResponse;

class APIClass
{
    protected $api_url;
    protected $api_key;
    protected $registered_domain = '';

    /**
     * @var int
     */
    protected int $api_version = 3;

    /**
     * @see https://apidocs.getresponse.com/v3
     * @var string
     */
    protected $api_base_url = 'https://api.getresponse.com/';
    protected $api_base_url_max = 'https://api3.getresponse360.com/';
    protected $api_base_url_max_poland = 'https://api3.getresponse360.pl/';

    public function __construct($api_key, $registered_domain = '', $country = '')
    {
        $this->api_url = $this->api_base_url . 'v' . $this->api_version . '/';

        $this->api_key = $api_key;

        if ( ! empty($registered_domain)) {

            $this->api_url = $this->api_base_url_max . 'v' . $this->api_version . '/';

            if ($country == 'poland') {
                $this->api_url = $this->api_base_url_max_poland . 'v' . $this->api_version . '/';
            }

            $this->registered_domain = $registered_domain;
        }
    }

    /**
     * @param $endpoint
     * @param array $args
     * @param string $method
     *
     * @return array
     * @throws \Exception
     */
    public function make_request($endpoint, array $args = [], string $method = 'get')
    {
        $url = $this->api_url . $endpoint;

        $wp_args = ['method' => strtoupper($method), 'timeout' => 30];

        $wp_args['headers'] = [
            'X-Auth-Token' => 'api-key ' . $this->api_key,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent'   => 'FuseWP',
        ];

        if ( ! empty($this->registered_domain)) {
            $wp_args['headers']['X-Domain'] = $this->registered_domain;
        }

        switch ($method) {
            case 'post':
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
