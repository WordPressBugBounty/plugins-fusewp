<?php

namespace FuseWP\Core\Integrations\ConstantContact;

use Authifly\Provider\ConstantContactV3 as AuthiflyConstantContact;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class ConstantContact extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'constantcontact';

        $this->title = 'Constant Contact';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/constantcontact-integration.png';

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
        $bucket = get_transient('fusewp_constant_contact_contact_fields');

        if ($bucket === false) {

            $bucket = [];

            $custom_fields = [
                'first_name'        => [__('First Name', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'last_name'         => [__('Last Name', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'job_title'         => [__('Job Title', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'company_name'      => [__('Company Name', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'birthday_month'    => [__('Birthday Month', 'fusewp'), ContactFieldEntity::NUMBER_FIELD],
                'birthday_day'      => [__('Birthday Day', 'fusewp'), ContactFieldEntity::NUMBER_FIELD],
                'anniversary'       => [__('Anniversary', 'fusewp'), ContactFieldEntity::DATE_FIELD],
                'phone_number'      => [__('Phone Number', 'fusewp'), ContactFieldEntity::TEXT_FIELD],

                // Home address
                'mohma_street'      => [__('Home Address Street', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'mohma_city'        => [__('Home Address City', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'mohma_state'       => [__('Home Address State', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'mohma_postal_code' => [__('Home Address Postal Code', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'mohma_country'     => [__('Home Address Country', 'fusewp'), ContactFieldEntity::TEXT_FIELD],

                // Work address
                'mowka_street'      => [__('Work Address Street', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'mowka_city'        => [__('Work Address City', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'mowka_state'       => [__('Work Address State', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'mowka_postal_code' => [__('Work Address Postal Code', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'mowka_country'     => [__('Work Address Country', 'fusewp'), ContactFieldEntity::TEXT_FIELD],

                // Other address
                'moota_street'      => [__('Other Address Street', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'moota_city'        => [__('Other Address City', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'moota_state'       => [__('Other Address State', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'moota_postal_code' => [__('Other Address Postal Code', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
                'moota_country'     => [__('Other Address Country', 'fusewp'), ContactFieldEntity::TEXT_FIELD],
            ];

            foreach ($custom_fields as $field_id => $field) {
                // skip custom fields if lite
                if ( ! fusewp_is_premium() && ! in_array($field_id, ['first_name', 'last_name'])) continue;

                $bucket[] = (new ContactFieldEntity())
                    ->set_id($field_id)
                    ->set_name($field[0])
                    ->set_data_type($field[1]);
            }

            if (fusewp_is_premium()) {

                try {

                    $fields = $this->apiClass()->getContactsCustomFields();

                    if (is_array($fields) && ! empty($fields)) {

                        // custom fields (cufd)
                        foreach ($fields as $field) {
                            $custom_fields['cufd_' . $field->custom_field_id] = $field->label;

                            $bucket[] = (new ContactFieldEntity())
                                ->set_id('cufd_' . $field->custom_field_id)
                                ->set_name($field->label)
                                ->set_data_type($field->type == 'date' ? ContactFieldEntity::DATE_FIELD : ContactFieldEntity::TEXT_FIELD);
                        }
                    }

                } catch (\Exception $e) {
                    fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
                }
            }

            // save cache.
            set_transient('fusewp_constant_contact_contact_fields', $bucket, 10 * MINUTE_IN_SECONDS);
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
        $lists = get_transient('fusewp_constant_contact_email_list');

        if ($lists === false) {

            try {

                $lists = $this->apiClass()->getContactList();

            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
                $lists = [];
            }

            // save cache.
            set_transient('fusewp_constant_contact_email_list', $lists, 10 * MINUTE_IN_SECONDS);
        }

        return $lists;
    }

    /**
     * @param $config_access_token
     *
     * @return AuthiflyConstantContact
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
            throw new \Exception(__('ConstantContact access token not found.', 'fusewp'));
        }

        $expires_at    = (int)fusewpVar($settings, 'expires_at', '');
        $refresh_token = fusewpVar($settings, 'refresh_token', '');

        $config = [
            // secret key and callback not needed but authifly requires they have a value hence the FUSEWP_OAUTH_URL constant and "__"
            'callback' => FUSEWP_OAUTH_URL,
            'keys'     => ['key' => '626f785d-a5a5-4a06-b4a8-9cd088a51394', 'secret' => '__']
        ];

        $instance = new AuthiflyConstantContact($config, null, new OAuthCredentialStorage([
            'constantcontactv3.access_token'  => $access_token,
            'constantcontactv3.refresh_token' => $refresh_token,
            'constantcontactv3.expires_at'    => $expires_at,
        ]));

        if ($instance->hasAccessTokenExpired()) {

            $result = $this->oauth_token_refresh($refresh_token);

            if ($result) {

                $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
                $old_data    = get_option($option_name, []);

                $old_data[$this->id]['access_token'] = $result['data']['access_token'];
                $old_data[$this->id]['expires_at']   = $result['data']['expires_at'];

                update_option($option_name, $old_data);

                $instance = new AuthiflyConstantContact($config, null,
                    new OAuthCredentialStorage([
                        'constantcontactv3.access_token'  => $result['data']['access_token'],
                        'constantcontactv3.refresh_token' => $refresh_token,
                        'constantcontactv3.expires_at'    => $result['data']['expires_at'],
                    ])
                );
            }
        }

        return $instance;
    }
}