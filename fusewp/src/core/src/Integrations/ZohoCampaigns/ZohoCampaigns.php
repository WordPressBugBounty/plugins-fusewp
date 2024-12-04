<?php

namespace FuseWP\Core\Integrations\ZohoCampaigns;

use Authifly\Provider\Zoho as AuthiflyZoho;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class ZohoCampaigns extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'zohocampaigns';

        $this->title = 'Zoho Campaigns';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/zohocampaigns-integration.png';

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

            return ! empty(fusewpVar($settings, 'access_token'));
        });
    }

    public function zohocampaigns_all_fields()
    {
        $field_names = get_transient('zohocampaigns_contact_fields');

        if ($field_names === false) {

            $response = $this->apiClass()->apiRequest('contact/allfields?type=json');

            if (isset($response->response->fieldnames->fieldname) && is_array($response->response->fieldnames->fieldname)) {

                $field_names = $response->response->fieldnames->fieldname;

                set_transient('zohocampaigns_contact_fields', $field_names, HOUR_IN_SECONDS);
            }
        }

        return $field_names;
    }

    /**
     * {@inheritDoc}
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        try {

            $is_lite_plugin = fusewp_is_premium() === false;

            $field_names = $this->zohocampaigns_all_fields();

            if (is_array($field_names)) {

                foreach ($field_names as $field) {

                    if ($field->FIELD_NAME == 'contact_email') continue;

                    if ($is_lite_plugin && ! in_array($field->FIELD_NAME, ['firstname', 'lastname'])) continue;

                    $data_type = ContactFieldEntity::TEXT_FIELD;

                    if (isset($field->UITYPE)) {

                        switch ($field->UITYPE) {
                            case 'checkbox':
                                $data_type = ContactFieldEntity::BOOLEAN_FIELD;
                                break;
                            case 'multiselectcheckbox':
                                $data_type = ContactFieldEntity::MULTISELECT_FIELD;
                                break;
                            case 'date':
                                $data_type = ContactFieldEntity::DATE_FIELD;
                                break;
                        }
                    }

                    $bucket[] = (new ContactFieldEntity())
                        ->set_id($field->DISPLAY_NAME)
                        ->set_name($field->DISPLAY_NAME)
                        ->set_data_type($data_type);
                }

                return $bucket;
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
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
        try {

            $offset = 0;
            $loop   = true;

            $lists_array = [];

            while ($loop === true) {

                $response = $this->apiClass()->apiRequest('getmailinglists?resfmt=JSON', 'GET', [
                    'range'     => 1000,
                    'fromindex' => $offset
                ]);

                if (isset($response->list_of_details) && is_array($response->list_of_details) && ! empty($response->list_of_details)) {

                    foreach ($response->list_of_details as $list) {
                        $lists_array[$list->listkey] = $list->listname;
                    }

                    if (count($response->list_of_details) < 1000) {
                        $loop = false;
                    }

                    $offset += 1000;
                } else {
                    throw new \Exception(wp_json_encode($response));
                }
            }

            return $lists_array;

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param $config_access_token
     *
     * @return AuthiflyZoho
     * @throws \Exception
     */
    public function apiClass($config_access_token = '')
    {
        $settings = $this->get_settings();

        $access_token = fusewpVar($settings, 'access_token', '');

        if ( ! empty($config_access_token)) {
            $access_token = $config_access_token;
        }

        if (empty($access_token)) {
            throw new \Exception(__('Zoho Campaigns access token not found.', 'fusewp'));
        }

        $expires_at      = (int)fusewpVar($settings, 'expires_at', '');
        $refresh_token   = fusewpVar($settings, 'refresh_token', '');
        $api_domain      = fusewpVar($settings, 'api_domain', '');
        $location        = fusewpVar($settings, 'location', '');
        $accounts_server = fusewpVar($settings, 'accounts_server', '');

        $config = [
            // secret key and callback not needed but authifly requires they have a value hence the FUSEWP_OAUTH_URL constant and "__"
            'callback' => FUSEWP_OAUTH_URL,
            'keys'     => ['key' => '1000.04HPT56MIFEJNXTNMVKUVSK0RA2OZQ', 'secret' => '__']
        ];

        $instance = new AuthiflyZoho($config, null, new OAuthCredentialStorage([
            'zoho.access_token'    => $access_token,
            'zoho.refresh_token'   => $refresh_token,
            'zoho.expires_at'      => $expires_at,
            'zoho.api_domain'      => $api_domain,
            'zoho.location'        => $location,
            'zoho.accounts_server' => $accounts_server
        ]));

        $instance->apiBaseUrl = sprintf('https://campaigns.zoho%s/api/v1.1/', $this->parse_location($location));

        if ($instance->hasAccessTokenExpired()) {

            $result = $this->oauth_token_refresh($refresh_token);

            if ($result) {

                $expires_at = $this->oauth_expires_at_transform($result['data']['expires_at']);

                $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
                $old_data    = get_option($option_name, []);

                $old_data[$this->id]['access_token'] = $result['data']['access_token'];
                // when a token is refreshed, zoho doesn't include a new refresh token as it never expires unless it was revoked.
                // And in that case, the user will re-authorize fusewp to generate a new token
                $old_data[$this->id]['expires_at'] = $expires_at;
                $old_data[$this->id]['api_domain'] = $result['data']['api_domain'];

                update_option($option_name, $old_data);

                $instance = new AuthiflyZoho($config, null,
                    new OAuthCredentialStorage([
                        'zoho.access_token'    => $result['data']['access_token'],
                        'zoho.refresh_token'   => $refresh_token,
                        'zoho.expires_at'      => $expires_at,
                        'zoho.api_domain'      => $result['data']['api_domain'],
                        'zoho.location'        => $location,
                        'zoho.accounts_server' => $accounts_server
                    ])
                );

                $instance->apiBaseUrl = sprintf('https://campaigns.zoho%s/api/v1.1/', $this->parse_location($location));
            }
        }

        return $instance;
    }

    public function parse_location($location)
    {
        switch ($location) {
            case 'us':
                $location = '.com';
                break;
            case 'eu':
                $location = '.eu';
                break;
            case 'au':
                $location = '.com.au';
                break;
            case 'cn':
                $location = '.com.cn';
                break;
            case 'jp':
                $location = '.jp';
                break;
            case 'ca':
                $location = 'cloud.ca';
                break;
        }

        return $location;
    }
}