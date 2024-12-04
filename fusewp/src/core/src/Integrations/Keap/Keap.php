<?php

namespace FuseWP\Core\Integrations\Keap;

use Authifly\Provider\Infusionsoft;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Keap extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'keap';

        $this->title = 'Keap';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/keap-integration.png';

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

        if ( ! fusewp_is_premium()) {
            $custom_fields = [
                'given_name'  => [
                    esc_html__('First Name', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'family_name' => [
                    esc_html__('Last Name', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ]
            ];

        } else {

            $custom_fields = [
                'given_name'     => [
                    esc_html__('First Name', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'family_name'    => [
                    esc_html__('Last Name', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'middle_name'    => [
                    esc_html__('Middle Name', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'preferred_name' => [
                    esc_html__('Nickname', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'job_title'      => [
                    esc_html__('Job Title', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'spouse_name'    => [
                    esc_html__('Spouse Name', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'website'        => [
                    esc_html__('Website', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'notes'          => [
                    esc_html__('Person Notes', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],

                'mocompany'          => [
                    esc_html__('Company', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],

                // Phone (mophne)
                'mophne_phone_1'     => [
                    esc_html__('Phone 1', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'mophne_phone_1_ext' => [
                    esc_html__('Phone 1 Extension', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'mophne_phone_2'     => [
                    esc_html__('Phone 2', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'mophne_phone_2_ext' => [
                    esc_html__('Phone 2 Extension', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],

                'anniversary' => [
                    esc_html__('Anniversary', 'fusewp'),
                    ContactFieldEntity::DATE_FIELD
                ],
                'birthday'    => [
                    esc_html__('Birthday', 'fusewp'),
                    ContactFieldEntity::DATE_FIELD
                ],

                'email_address_2'            => [
                    esc_html__('Email Address 2', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'email_address_3'            => [
                    esc_html__('Email Address 3', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],

                // billing address (moblla)
                'moblla_address_line1'       => [
                    esc_html__('Billing Address Street (Line 1)', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'moblla_address_line2'       => [
                    esc_html__('Billing Address Street (Line 2)', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'moblla_address_city'        => [
                    esc_html__('Billing Address City', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                // country_code is required if region (state) is specified
                'moblla_address_state'       => [
                    esc_html__('Billing Address State', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                // alpha-3 code representation of billing country. E.g NGA, USA etc
                // see https://community.infusionsoft.com/t/invalid-country-code-and-region-what-is-valid/13354/2
                'moblla_address_country'     => [
                    esc_html__('Billing Address Country', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                // Field used to store postal codes containing a combination of letters and numbers ex. 'EC1A', 'S1 2HE', '75000'
                // Particularly useful for international country postal code.
                'moblla_address_postal_code' => [
                    esc_html__('Billing Address Postal Code', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                // Mainly used in the United States, this is typically numeric. ex. '85001', '90002'
                // Note: this is to be used instead of 'postal_code', not in addition to.
                'moblla_address_zip_code'    => [
                    esc_html__('Billing Address Zip Code', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                // If you have an extended zip code, put the last four digits (those after the hyphen) here
                //Last four of a full zip code ex. '8244', '4320'. Totally optional
                // This field is supplemental to the zip_code field, otherwise will be ignored.
                'moblla_address_zip_four'    => [
                    esc_html__('Billing Address Zip Extension', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],

                // shipping address (moshpa)
                'moshpa_address_line1'       => [
                    esc_html__('Shipping Address Street (Line 1)', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'moshpa_address_line2'       => [
                    esc_html__('Shipping Address Street (Line 2)', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'moshpa_address_city'        => [
                    esc_html__('Shipping Address City', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'moshpa_address_state'       => [
                    esc_html__('Shipping Address State', 'fusewp'),
                    'moshpa_address_country' => esc_html__('Shipping Address Country', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'moshpa_address_postal_code' => [
                    esc_html__('Shipping Address Postal Code', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'moshpa_address_zip_code'    => [
                    esc_html__('Shipping Address Zip Code', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'moshpa_address_zip_four'    => [
                    esc_html__('Shipping Address Zip Extension', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],

                // other address (motha)
                'motha_address_line1'        => [
                    esc_html__('Other Address Street (Line 1)', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'motha_address_line2'        => [
                    esc_html__('Other Address Street (Line 2)', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'motha_address_city'         => [
                    esc_html__('Other Address City', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'motha_address_state'        => [
                    esc_html__('Other Address State', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'motha_address_country'      => [
                    esc_html__('Other Address Country', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'motha_address_postal_code'  => [
                    esc_html__('Other Address Postal Code', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'motha_address_zip_code'     => [
                    esc_html__('Other Address Zip Code', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'motha_address_zip_four'     => [
                    esc_html__('Other Address Zip Extension', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],

                // social network(mosonk)
                'mosonk_facebook'            => [
                    esc_html__('Facebook Username', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'mosonk_twitter'             => [
                    esc_html__('Twitter Username', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
                'mosonk_linkedin'            => [
                    esc_html__('LinkedIn Username', 'fusewp'),
                    ContactFieldEntity::TEXT_FIELD
                ],
            ];

            try {

                $response = $this->apiClass()->apiRequest("contacts/model");

                if (isset($response->custom_fields) && is_array($response->custom_fields)) {
                    // custom fields (cufd)
                    foreach ($response->custom_fields as $field) {

                        $field_type = ContactFieldEntity::TEXT_FIELD;

                        switch ($field->field_type) {
                            case 'DateTime':
                                $field_type = ContactFieldEntity::DATETIME_FIELD;
                                break;
                            case 'Date':
                                $field_type = ContactFieldEntity::DATE_FIELD;
                                break;
                            case 'ListBox':
                                $field_type = ContactFieldEntity::MULTISELECT_FIELD;
                                break;
                        }

                        $custom_fields['cufd_' . $field->id] = [$field->label, $field_type];
                    }
                }

            } catch (\Exception $e) {

                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
        }

        foreach ($custom_fields as $custom_field_id => $custom_field) {

            $bucket[] = (new ContactFieldEntity())
                ->set_id($custom_field_id)
                ->set_name($custom_field[0])
                ->set_data_type($custom_field[1]);
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

            return $this->apiClass()->getTags();

        } catch (\Exception $e) {

            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param $config_access_token
     *
     * @return Infusionsoft
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
            throw new \Exception(__('Keap access token not found.', 'fusewp'));
        }

        $expires_at    = (int)fusewpVar($settings, 'expires_at', '');
        $refresh_token = fusewpVar($settings, 'refresh_token', '');

        $config = [
            // secret key and callback not needed but authifly requires they have a value hence the FUSEWP_OAUTH_URL constant and "__"
            'callback' => FUSEWP_OAUTH_URL,
            'keys'     => ['key' => 'C48TBcZ1aJAwiA3j0qpI7fgWk8GodldS2EuoXn6eZDaIxSfm', 'secret' => '__']
        ];

        $instance = new Infusionsoft($config, null, new OAuthCredentialStorage([
            'infusionsoft.access_token'  => $access_token,
            'infusionsoft.refresh_token' => $refresh_token,
            'infusionsoft.expires_at'    => $expires_at
        ]));

        if ($instance->hasAccessTokenExpired()) {

            $result = $this->oauth_token_refresh($refresh_token);

            if ($result) {

                $expires_at = $this->oauth_expires_at_transform($result['data']['expires_at']);

                $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
                $old_data    = get_option($option_name, []);

                $old_data[$this->id]['access_token']  = $result['data']['access_token'];
                $old_data[$this->id]['refresh_token'] = $result['data']['refresh_token'];
                $old_data[$this->id]['expires_at']    = $expires_at;

                update_option($option_name, $old_data);

                $instance = new Infusionsoft($config, null,
                    new OAuthCredentialStorage([
                        'infusionsoft.access_token'  => $result['data']['access_token'],
                        'infusionsoft.refresh_token' => $result['data']['refresh_token'],
                        'infusionsoft.expires_at'    => $expires_at
                    ])
                );
            }
        }

        return $instance;
    }
}