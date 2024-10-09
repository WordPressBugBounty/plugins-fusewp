<?php

namespace FuseWP\Core\Integrations\Aweber;

use Authifly\Provider\Aweber2 as AuthiflyAweber;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Aweber extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'aweber';

        $this->title = 'AWeber';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/aweber-integration.svg';

        $this->adminSettingsPageInstance = new AdminSettingsPage($this);

        parent::__construct();
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'access_token')) && ! empty(fusewpVar($settings, 'account_id'));
        });
    }

    public function set_bulk_sync_throttle_seconds($seconds)
    {
        return 2;
    }

    public function get_account_id()
    {
        return fusewpVar($this->get_settings(), 'account_id', '');
    }

    /**
     * {@inheritDoc}
     */
    public function get_contact_fields($list_id = '')
    {
        $cache_key = 'fusewp_aweber_contact_field_' . $list_id;

        $fieldsBucket = get_transient($cache_key);

        if ($fieldsBucket === false) {

            $fieldsBucket = [
                'fusewpFirstName' => esc_html__('First Name', 'fusewp'),
                'fusewpLastName'  => esc_html__('Last Name', 'fusewp'),
            ];

            if (fusewp_is_premium()) {

                try {

                    $response = $this->apiClass()->getListCustomFields($this->get_account_id(), $list_id);

                    if (is_array($response) && ! empty($response)) {

                        foreach ($response as $customField) {

                            $fieldsBucket[$customField->name] = $customField->name;
                        }
                    }

                } catch (\Exception $e) {
                    fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
                }
            }

            $fieldsBucket['fusewpIPaddress'] = esc_html__('IP Address', 'fusewp');

            set_transient($cache_key, $fieldsBucket, HOUR_IN_SECONDS);
        }

        $bucket = [];

        foreach ($fieldsBucket as $id => $label) {
            $bucket[] = (new ContactFieldEntity())->set_id($id)->set_name($label);
        }

        return $bucket;
    }

    /**
     * @return SyncAction
     */
    public function get_sync_action()
    {
        return new SyncAction($this);
    }

    public function connection_settings()
    {
        return $this->adminSettingsPageInstance->connection_settings();
    }

    public function get_email_list()
    {
        $lists_array = get_transient('fusewp_aweber_email_list');

        if ($lists_array === false) {

            try {

                $offset = 0;
                $loop   = true;
                $limit  = 100;

                $lists_array = [];

                while ($loop === true) {

                    $response = $this->apiClass()->fetchEmailListNameAndId($this->get_account_id(), $offset, $limit);

                    // an array with list id as key and name as value.
                    if (is_array($response)) {
                        foreach ($response as $list) {
                            $lists_array[$list[0]] = $list[1];
                        }

                        if (count($response) < $limit) {
                            $loop = false;
                        }

                        $offset += $limit;
                    } else {
                        $loop = false;
                    }
                }

                // save cache.
                set_transient('fusewp_aweber_email_list', $lists_array, HOUR_IN_SECONDS);

            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
        }

        return $lists_array;
    }

    /**
     * @param $refresh_token
     *
     * @return false|mixed
     * @throws \Exception
     */
    public function handle_refresh_token($refresh_token)
    {
        $result = $this->oauth_token_refresh($refresh_token);

        if ($result) {

            $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
            $old_data    = get_option($option_name, []);

            $old_data[$this->id]['access_token']  = $result['data']['access_token'];
            $old_data[$this->id]['refresh_token'] = $result['data']['refresh_token'];
            $old_data[$this->id]['expires_at']    = $result['data']['expires_at'];

            update_option($option_name, $old_data);

            return $result;
        }

        return false;
    }

    /**
     * @param string $config_access_token
     * @param bool $force_refresh
     *
     * @return AuthiflyAweber
     * @throws \Exception
     */
    public function apiClass($config_access_token = '', $force_refresh = false)
    {
        $settings = $this->get_settings();

        $access_token = fusewpVar($settings, 'access_token', '');

        if ( ! empty($config_access_token)) {
            $access_token = $config_access_token;
        }

        if (empty($access_token)) {
            throw new \Exception(__('Aweber access token not found.', 'fusewp'));
        }

        $expires_at    = (int)fusewpVar($settings, 'expires_at', '');
        $refresh_token = fusewpVar($settings, 'refresh_token', '');

        $config = [
            // secret key and callback not needed but authifly requires they have a value hence the FUSEWP_OAUTH_URL constant and "__"
            'callback' => FUSEWP_OAUTH_URL,
            'keys'     => ['key' => 'g02XnAY13iVLJrSGzqC3g0gZMBDPvUC8', 'secret' => '__']
        ];

        $instance = new AuthiflyAweber($config, null, new OAuthCredentialStorage([
            'aweber2.access_token'  => $access_token,
            'aweber2.refresh_token' => $refresh_token,
            'aweber2.expires_at'    => $expires_at,
        ]));

        if ($instance->hasAccessTokenExpired() || $force_refresh === true) {

            $result = $this->handle_refresh_token($refresh_token);

            if ($result) {

                $config['access_token'] = $result['data']['access_token'];

                $instance = new AuthiflyAweber($config, null, new OAuthCredentialStorage([
                        'aweber2.access_token'  => $result['data']['access_token'],
                        'aweber2.refresh_token' => $result['data']['refresh_token'],
                        'aweber2.expires_at'    => $result['data']['expires_at'],
                    ])
                );
            }
        }

        return $instance;
    }

    /**
     * @param $url
     * @param $method
     * @param $parameters
     * @param $headers
     *
     * @return mixed
     * @throws \Exception
     */
    public function make_request($url, $method = 'GET', $parameters = [], $headers = [])
    {
        try {

            $apiClass = $this->apiClass();

            $response = $apiClass->apiRequest($url, $method, $parameters, $headers);

            $status_code = $apiClass->getHttpClient()->getResponseHttpCode();

            return ['status_code' => $status_code, 'body' => $response];

        } catch (\Exception $e) {

            if (strstr($e->getMessage(), 'HTTP error 401') !== false) {

                $apiClass = $this->apiClass('', true);

                $response = $apiClass->apiRequest($url, $method, $parameters, $headers);

                $status_code = $apiClass->getHttpClient()->getResponseHttpCode();

                return ['status_code' => $status_code, 'body' => $response];
            }

            throw new \Exception($e->getMessage(), $e->getCode());
        }
    }
}