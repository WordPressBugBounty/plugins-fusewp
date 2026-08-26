<?php

namespace FuseWP\Core\Integrations\Sender;

class APIClass
{
    protected $api_token;
    protected $api_url;
    protected $api_version = 2;
    protected $base_url = 'https://api.sender.net/';

    /**
     * Maximum number of retry attempts on 429 responses.
     */
    const MAX_RETRIES = 5;

    public function __construct($api_token)
    {
        $this->api_token = $api_token;
        $this->api_url   = $this->base_url . 'v' . $this->api_version . '/';
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
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'FuseWP; ' . home_url(),
            ]
        ];

        switch (strtolower($method)) {
            case 'post':
            case 'put':
            case 'patch':
            case 'delete':
                $wp_args['body'] = json_encode($args);
                break;
            case 'get':
                $url = add_query_arg($args, $url);
                break;
        }

        $retries = 0;

        while ($retries <= self::MAX_RETRIES) {

            $response = wp_remote_request($url, $wp_args);

            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }

            $response_http_code = wp_remote_retrieve_response_code($response);

            // Handle rate limiting (HTTP 429)
            if ($response_http_code === 429) {

                if ($retries >= self::MAX_RETRIES) {
                    throw new \Exception(
                        __('Sender.net API rate limit exceeded. Please try again later.', 'fusewp'),
                        429
                    );
                }

                $retry_after = $this->get_retry_after($response);

                sleep($retry_after);

                $retries++;
                continue;
            }

            $response_body = wp_remote_retrieve_body($response);

            if ( ! fusewp_is_http_code_success($response_http_code)) {
                throw new \Exception($response_body, $response_http_code);
            }

            return ['status_code' => $response_http_code, 'body' => json_decode($response_body)];
        }

        throw new \Exception(
            __('Sender.net API rate limit exceeded after maximum retries.', 'fusewp'),
            429
        );
    }

    /**
     * Calculate how many seconds to wait before retrying after a 429 response.
     * Uses the X-RateLimit-Reset header if available, otherwise falls back
     * to exponential backoff (1, 2, 4, 8, 16 … seconds).
     *
     * @param array|\WP_HTTP_Requests_Response $response
     * @param int $retry_count Current retry attempt (0-based).
     *
     * @return int Seconds to wait.
     */
    protected function get_retry_after($response, $retry_count = 0)
    {
        // X-RateLimit-Reset is the Unix timestamp when the window resets.
        $reset_header = wp_remote_retrieve_header($response, 'x-ratelimit-reset');

        if ( ! empty($reset_header) && is_numeric($reset_header)) {
            $wait = (int) $reset_header - time();
            if ($wait > 0) {
                return $wait;
            }
        }

        // Retry-After header (seconds or HTTP-date).
        $retry_after_header = wp_remote_retrieve_header($response, 'retry-after');

        if ( ! empty($retry_after_header)) {
            if (is_numeric($retry_after_header)) {
                return max(1, (int) $retry_after_header);
            }
            // It may be an HTTP-date string.
            $timestamp = strtotime($retry_after_header);
            if ($timestamp !== false) {
                $wait = $timestamp - time();
                if ($wait > 0) {
                    return $wait;
                }
            }
        }

        // Exponential backoff fallback: 1, 2, 4, 8, 16 seconds.
        return (int) pow(2, $retry_count);
    }
}