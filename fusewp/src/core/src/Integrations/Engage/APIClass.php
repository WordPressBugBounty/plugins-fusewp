<?php

namespace FuseWP\Core\Integrations\Engage;

class APIClass
{
    protected $api_url;
    protected $api_version = 'v1';

    protected $public_key;
    protected $private_key;

    /**
     * @var string
     */
    protected $api_base_url = 'https://api.engage.so/';

    public function __construct($public_key, $private_key)
    {
        $this->public_key  = $public_key;
        $this->private_key = $private_key;
        $this->api_url     = $this->api_base_url . $this->api_version;
    }

    /**
     * @param string $endpoint
     * @param array $args
     * @param string $method
     *
     * @return array
     * @throws \Exception
     */
    public function make_request($endpoint, array $args = [], string $method = 'get')
    {
        $url = $this->api_url . '/' . ltrim($endpoint, '/');

        $wp_args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'FuseWP; ' . home_url(),
            ],
        ];

        // HTTP Basic Authentication: public key as username, private key as password
        $wp_args['headers']['Authorization'] = 'Basic ' . base64_encode($this->public_key . ':' . $this->private_key);

        switch ($method) {
            case 'post':
            case 'put':
                if ( ! empty($args)) {
                    $wp_args['body'] = json_encode($args);
                }
                break;
            case 'get':
                if ( ! empty($args)) {
                    $url = add_query_arg($args, $url);
                }
                break;
            case 'delete':
                // DELETE requests typically don't have a body
                break;
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
