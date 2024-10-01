<?php

namespace FuseWP\Core\Integrations\Klaviyo;

class APIClass
{
    protected $api_key;

    protected $revision = '2023-12-15';

    /**
     * @var string
     */
    protected $api_url = 'https://a.klaviyo.com/api/';


    public function __construct($api_key)
    {
        $this->api_key = $api_key;
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
        $wp_args = ['method' => strtoupper($method), 'timeout' => 30];

        $url = $this->api_url . $endpoint;

        $wp_args['headers'] = [
            "Authorization" => sprintf('Klaviyo-API-Key %s', $this->api_key),
            "revision"      => $this->revision,
            "Content-Type"  => 'application/json'
        ];

        if ($method !== 'get') {
            $args = json_encode($args);
        }

        switch ($method) {
            case 'post':
            case 'patch':
                $wp_args['body'] = $args;
                break;
            case 'get':
                $url = add_query_arg($args, $url);
                break;
            default:
                $wp_args['body'] = $args;
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

        $response_body = json_decode($response_body);

        return ['status_code' => $response_http_code, 'body' => $response_body];
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function get_lists()
    {
        $flag = true;

        $lists_array = [];

        $endpoint = 'lists/';

        while ($flag === true) {

            $response = $this->make_request($endpoint);

            if (isset($response['body']->data)) {

                $lists = $response['body']->data;

                if (is_array($lists)) {

                    foreach ($lists as $list) {
                        $lists_array[$list->id] = $list->attributes->name;
                    }
                }

                if (isset($response['body']->links->next) && ! empty($response['body']->links->next)) {
                    $endpoint = str_replace($this->api_url, '', $response['body']->links->next);
                } else {
                    $flag = false;
                }

            } else {
                $flag = false;
            }
        }

        return $lists_array;
    }

    /**
     * @param string $list_id
     * @param array $properties extra data to tie to the subscriber
     *
     * @return array
     * @throws \Exception
     */
    public function add_subscriber($list_id, $properties = [], $update_profile_id = false)
    {
        $body = $properties['main'];
        if (isset($properties['extra'])) {
            $body['properties'] = $properties['extra'];
        }
        $payload = [
            'data' => [
                'type'       => 'profile',
                'attributes' => $body
            ]
        ];

        if ($update_profile_id) {

            $payload['data']['id'] = $update_profile_id;
            $response              = $this->make_request("profiles/{$update_profile_id}/", $payload, 'patch');

            $this->add_profile_to_list($update_profile_id, $list_id, $properties);

        } else {

            $response = $this->make_request("profiles/", $payload, 'post');

            if (isset($response['body']->data->id)) {
                $this->add_profile_to_list($response['body']->data->id, $list_id, $properties);
            }
        }

        return $response;
    }

    /**
     * @throws \Exception
     */
    private function add_profile_to_list($profile_id, $list_id, $properties)
    {
        $payload2 = [
            'data' => [
                'type'          => 'profile-subscription-bulk-create-job',
                'attributes'    => [
                    'profiles' => [
                        'data' => [
                            [
                                'type'       => 'profile',
                                'id'         => $profile_id,
                                'attributes' => [
                                    'email'         => $properties['main']['email'],
                                    'subscriptions' => [
                                        'email' => [
                                            'marketing' => [
                                                'consent' => 'SUBSCRIBED'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'relationships' => [
                    'list' => [
                        'data' => [
                            'type' => 'list',
                            'id'   => $list_id
                        ]
                    ]
                ]
            ]
        ];

        $this->make_request("profile-subscription-bulk-create-jobs/", $payload2, 'post');
    }
}