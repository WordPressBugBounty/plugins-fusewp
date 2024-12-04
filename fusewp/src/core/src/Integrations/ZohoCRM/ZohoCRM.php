<?php

namespace FuseWP\Core\Integrations\ZohoCRM;

use Authifly\Provider\Zoho as AuthiflyZoho;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class ZohoCRM extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'zohocrm';

        $this->title = 'Zoho CRM';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/zohocrm-integration.svg';

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
            ->set_id('First_Name')
            ->set_name('First Name')
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('Last_Name')
            ->set_name('Last Name')
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('Email')
            ->set_name('Email')
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        if (fusewp_is_premium()) {

            try {

                // https://www.zoho.com/crm/developer/docs/api/v6/layouts-meta.html
                $response = $this->apiClass()->apiRequest("settings/layouts?module={$list_id}");

                if (isset($response->layouts[0]->sections) && is_array($response->layouts[0]->sections)) {

                    foreach ($response->layouts[0]->sections as $section) {

                        foreach ($section->fields as $field) {

                            // skip unsupported field types
                            if (in_array($field->data_type, [
                                'ownerlookup',
                                'lookup',
                                'boolean',
                                'currency',
                                'profileimage'
                            ])) {
                                continue;
                            }

                            if (in_array($field->api_name, [
                                'Description',
                                'Lead_Source',
                                'Owner',
                                'Email',
                                'First_Name',
                                'Last_Name',
                                'Created_Time',
                                'Modified_Time',
                                'Last_Activity_Time'
                            ])) {
                                continue;
                            }

                            $data_type = ContactFieldEntity::TEXT_FIELD;

                            switch ($field->data_type) {
                                case 'integer':
                                case 'bigint':
                                    $data_type = ContactFieldEntity::NUMBER_FIELD;
                                    break;
                                case 'date':
                                    $data_type = ContactFieldEntity::DATE_FIELD;
                                    break;
                                case 'datetime':
                                    $data_type = ContactFieldEntity::DATETIME_FIELD;
                                    break;
                            }

                            $bucket[] = (new ContactFieldEntity())
                                ->set_id($field->api_name)
                                ->set_name($field->field_label)
                                ->set_data_type($data_type);
                        }
                    }

                    return $bucket;
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

    public function get_email_list()
    {
        return [
            'Contacts' => esc_html__('Contacts', 'fusewp'),
            'Leads'    => esc_html__('Leads', 'fusewp')
        ];
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
            throw new \Exception(__('ZohoCRM access token not found.', 'fusewp'));
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

        $instance->apiBaseUrl = $api_domain . '/crm/v6/';

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

                $instance->apiBaseUrl = $api_domain . '/crm/v3/';
            }
        }

        return $instance;
    }
}