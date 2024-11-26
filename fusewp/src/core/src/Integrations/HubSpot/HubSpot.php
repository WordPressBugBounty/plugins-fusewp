<?php

namespace FuseWP\Core\Integrations\HubSpot;

use Authifly\Provider\Hubspot as AuthiflyHubSpot;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class HubSpot extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'hubspot';

        $this->title = 'HubSpot';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/hubspot-integration.png';

        $this->adminSettingsPageInstance = new AdminSettingsPage($this);

        parent::__construct();

        add_action('fusewp_cache_clearing_' . $this->id, [$this, 'clear_cache']);
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT, self::CACHE_CLEARING_SUPPORT];
    }

    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'access_token'));
        });
    }

    public function clear_cache()
    {
        delete_transient('fusewp_hubspot_contact_fields');
        delete_transient('fusewp_hubspot_get_owners');
        $this->delete_transients_by_prefix('fusewp_hubspot_contact_id_');
        $this->delete_transients_by_prefix('fusewp_hubspot_get_property_options_');
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

            try {

                $fields = get_transient('fusewp_hubspot_contact_fields');

                if ($fields === false) {

                    $fields = $this->apiClass()->apiRequest("crm/v3/properties/contacts");

                    set_transient('fusewp_hubspot_contact_fields', $fields, 12 * HOUR_IN_SECONDS);
                }

                if ( ! empty($fields->results)) {

                    foreach ($fields->results as $field) {

                        //Ensure the field is not automatically set by Hubspot
                        if ($field->modificationMetadata->readOnlyValue === true) continue;

                        if ($field->fieldType == 'calculation_equation') continue;

                        // legacy properties
                        if (in_array($field->name, ['owneremail', 'ownername'])) continue;

                        if (in_array($field->name, [
                            'hs_lead_status',
                            'lifecyclestage',
                            'email',
                            'firstname',
                            'lastname'
                        ])) continue;

                        switch ($field->type) {
                            case 'number':
                                $datatype = ContactFieldEntity::NUMBER_FIELD;
                                break;
                            case 'date':
                                $datatype = ContactFieldEntity::DATE_FIELD;
                                break;
                            case 'dateTime':
                                $datatype = ContactFieldEntity::DATETIME_FIELD;
                                break;
                            case 'bool':
                                $datatype = ContactFieldEntity::BOOLEAN_FIELD;
                                break;
                            default:
                                $datatype = ContactFieldEntity::TEXT_FIELD;
                                break;
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($field->name)
                            ->set_name($field->label)
                            ->set_data_type($datatype);
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

    public function get_email_list()
    {
        $lists = get_transient('fusewp_hubspot_email_list');

        if (empty($lists)) {

            try {

                $lists = $this->apiClass()->getEmailList();

            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
                $lists = [];
            }

            // save cache.
            set_transient('fusewp_hubspot_email_list', $lists, 12 * HOUR_IN_SECONDS);
        }

        return $lists;
    }

    /**
     * @param $config_access_token
     *
     * @return AuthiflyHubSpot
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
            throw new \Exception(__('HubSpot access token not found.', 'fusewp'));
        }

        $expires_at    = (int)fusewpVar($settings, 'expires_at', '');
        $refresh_token = fusewpVar($settings, 'refresh_token', '');

        $config = [
            // secret key and callback not needed but authifly requires they have a value hence the FUSEWP_OAUTH_URL constant and "__"
            'callback' => FUSEWP_OAUTH_URL,
            'keys'     => ['key' => '88558816-f053-401e-89a9-949ace1f480b', 'secret' => '__']
        ];

        $instance = new AuthiflyHubSpot($config, null, new OAuthCredentialStorage([
            'hubspot.access_token'  => $access_token,
            'hubspot.refresh_token' => $refresh_token,
            'hubspot.expires_at'    => $expires_at,
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

                $instance = new AuthiflyHubSpot($config, null,
                    new OAuthCredentialStorage([
                        'hubspot.access_token'  => $result['data']['access_token'],
                        'hubspot.refresh_token' => $refresh_token,
                        'hubspot.expires_at'    => $result['data']['expires_at'],
                    ])
                );
            }
        }

        return $instance;
    }
}