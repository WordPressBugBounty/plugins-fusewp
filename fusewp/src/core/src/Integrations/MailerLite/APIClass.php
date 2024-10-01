<?php

namespace FuseWP\Core\Integrations\MailerLite;

class APIClass
{
    protected $api_key;

    protected $api_url;
    /**
     * @var string
     */
    protected $api_url_base = 'https://connect.mailerlite.com/';

    public function __construct($api_key)
    {
        $this->api_key = $api_key;
        $this->api_url = $this->api_url_base . 'api/';
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

        $wp_args = ['method' => strtoupper($method), 'timeout' => 30, 'user-agent' => 'FuseWP MailerLite Connect (https://fusewp.com)'];

        $wp_args['headers'] = [
            "Content-Type"  => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key
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

        if (isset($response['body']['errors'])) {
            throw new \Exception($response['body']['errors']);
        }

        $response_http_code = wp_remote_retrieve_response_code($response);

        $response_body = wp_remote_retrieve_body($response);

        if ($response_http_code >= 200 && $response_http_code <= 299) {
            $response_body      = json_decode(wp_remote_retrieve_body($response), true);
        }

        return ['status' => $response_http_code, 'body' => $response_body];
    }
}