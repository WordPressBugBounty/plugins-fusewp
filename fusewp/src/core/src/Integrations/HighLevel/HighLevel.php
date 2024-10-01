<?php

namespace FuseWP\Core\Integrations\HighLevel;

use Authifly\Provider\HighLevel as AuthiflyHighLevel;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class HighLevel extends AbstractIntegration
{
    public $locationId = '';

    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'gohl';

        $this->title = 'HighLevel';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/highlevel-integration.png';

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

    /**
     * {@inheritDoc}
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpFirstName')
            ->set_name(esc_html__('First Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpLastName')
            ->set_name(esc_html__('Last Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);


        if (fusewp_is_premium()) {

            $fields = [
                'gender'      => 'Gender',
                'phone'       => 'Phone',
                'address1'    => 'Street Address',
                'city'        => 'City',
                'state'       => 'State',
                'country'     => 'Country',
                'postalCode'  => 'Postal Code',
                'website'     => 'Website',
                'dateOfBirth' => 'Date of Birth',
                'companyName' => 'Company Name',
                'timezone'    => 'Time Zone',
                'source'      => 'Source',
            ];

            foreach ($fields as $k => $v) {

                $bucket[] = (new ContactFieldEntity())
                    ->set_id($k)
                    ->set_name($v)
                    ->set_data_type(ContactFieldEntity::TEXT_FIELD);
            }

            try {

                $custom_fields = $this->make_request('locations/{locationId}/customFields');

                if (isset($custom_fields->customFields)) {

                    foreach ($custom_fields->customFields as $custom_field) {

                        switch ($custom_field->dataType) {
                            case 'DATE':
                                $data_type = ContactFieldEntity::DATE_FIELD;
                                break;
                            case 'NUMERICAL':
                                $data_type = ContactFieldEntity::NUMBER_FIELD;
                                break;
                            case 'MULTIPLE_OPTIONS':
                                $data_type = ContactFieldEntity::MULTISELECT_FIELD;
                                break;
                            default:
                                $data_type = ContactFieldEntity::TEXT_FIELD;
                        }

                        $fields['ghl_custom_' . $custom_field->id] = $custom_field->name;

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id('ghl_custom_' . $custom_field->id)
                            ->set_name($custom_field->name)
                            ->set_data_type($data_type);
                    }
                }

            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
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

    /** we are using tags as email list for segmentation */
    public function get_email_list()
    {
        $tags = [];

        try {

            $response = $this->make_request('locations/{locationId}/tags');

            if (is_array($response->tags)) {
                foreach ($response->tags as $item) {
                    $tags[$item->name] = $item->name;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $tags;
    }

    /**
     * @param $config_access_token
     *
     * @return AuthiflyHighLevel
     * @throws \Exception
     */
    public function apiClass($config_access_token = '')
    {
        $settings = $this->get_settings();

        $access_token     = fusewpVar($settings, 'access_token', '');
        $this->locationId = fusewpVar($settings, 'locationId', '');

        if ( ! empty($config_access_token)) {
            $access_token = $config_access_token;
        }

        if (empty($access_token)) {
            throw new \Exception(__('HighLevel access token not found.', 'fusewp'));
        }

        $expires_at    = (int)fusewpVar($settings, 'expires_at', '');
        $refresh_token = fusewpVar($settings, 'refresh_token', '');

        $config = [
            // secret key and callback not needed but authifly requires they have a value hence the FUSEWP_OAUTH_URL constant and "__"
            'callback' => FUSEWP_OAUTH_URL,
            'keys'     => ['key' => '6600557458758328f9937a24-lvdo9aqg', 'secret' => '__']
        ];

        $instance = new AuthiflyHighLevel($config, null, new OAuthCredentialStorage([
            'highlevel.access_token'  => $access_token,
            'highlevel.refresh_token' => $refresh_token,
            'highlevel.expires_at'    => $expires_at,
        ]));

        if ($instance->hasAccessTokenExpired()) {

            $result = $this->oauth_token_refresh($refresh_token);

            if ($result) {

                $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
                $old_data    = get_option($option_name, []);

                $old_data[$this->id]['access_token']  = $result['data']['access_token'];
                $old_data[$this->id]['refresh_token'] = $result['data']['refresh_token'];
                $old_data[$this->id]['expires_at']    = $result['data']['expires_at'];

                update_option($option_name, $old_data);

                $instance = new AuthiflyHighLevel($config, null,
                    new OAuthCredentialStorage([
                        'highlevel.access_token'  => $result['data']['access_token'],
                        'highlevel.refresh_token' => $refresh_token,
                        'highlevel.expires_at'    => $this->oauth_expires_at_transform($result['data']['expires_at']),
                    ])
                );
            }
        }

        return $instance;
    }

    /**
     * @throws \Exception
     */
    public function make_request($url, $method = 'GET', $parameters = [])
    {
        $instance = $this->apiClass();

        $headers = [
            'Content-Type' => 'application/json',
            'Version'      => '2021-07-28',
        ];

        $url = str_replace('{locationId}', $this->locationId, $url);

        if (is_array($parameters)) {
            $parameters = array_map(function ($val) {

                if ($val == "{locationId}") $val = $this->locationId;

                return $val;

            }, $parameters);
        }

        return $instance->apiRequest($url, $method, $parameters, $headers);
    }
}