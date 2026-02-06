<?php

namespace FuseWP\Core\Integrations\Beehiiv;

class APIClass
{
    /**
     * @var string
     */
    protected $api_key;

    /**
     * @var string
     */
    protected $publication_id;

    /**
     * @var string
     */
    protected $api_url;

    /**
     * @var int
     */
    protected $api_version = 2;

    /**
     * @var string
     */
    protected $api_url_base = 'https://api.beehiiv.com/';

    public function __construct($api_key, $publication_id)
    {
        $this->publication_id = $publication_id;
        $this->api_key        = $api_key;
        $this->api_url        = $this->api_url_base . 'v' . $this->api_version . '/';
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
        $url = str_replace('{publicationId}', $this->publication_id, $this->api_url . $endpoint);

        $wp_args = [
            'method'  => strtoupper($method),
            'timeout' => 60, // a customer site had issues when this was 30 seconds
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->api_key)
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
                // this is needed for fetching query with array values. default didn't work
                // Process all array parameters to use the format param[]=value
                $array_params = [];
                foreach ($args as $key => $value) {
                    if (is_array($value)) {
                        // Handle array parameters
                        foreach ($value as $array_value) {
                            $array_params[] = $key . '[]=' . urlencode($array_value);
                        }
                        // Remove this parameter from args to prevent it from being processed by add_query_arg
                        unset($args[$key]);
                    }
                }

                // First add the regular parameters
                $url = add_query_arg($args, $url);

                // Then append the properly formatted array parameters
                if ( ! empty($array_params)) {
                    $separator = (strpos($url, '?') !== false) ? '&' : '?';
                    $url       .= $separator . implode('&', $array_params);
                }
                break;
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

        return ['status_code' => $response_http_code, 'body' => json_decode($response_body)];
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
