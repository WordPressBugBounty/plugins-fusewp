<?php

namespace FuseWP\Core\Integrations\Copper;

use Authifly\Provider\Copper as AuthiflyCopper;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Copper extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'copper';

        $this->title = 'Copper CRM';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/copper-integration.png';

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

            return !empty(fusewpVar($settings, 'access_token'));
        });
    }

    /**
     * {@inheritDoc}
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        // gotten from api response of person/lead model eg https://developer.copper.com/people/fetch-a-person-by-id.html
        // and https://developer.copper.com/leads/fetch-a-lead-by-id.html
        $core_fields = [
            'first_name' => 'First Name',
            'middle_name' => 'Middle Name',
            'last_name' => 'Last Name',
        ];

        if (fusewp_is_premium()) {

            $core_fields += [
                'prefix' => 'Prefix',
                'suffix' => 'Suffix',
                'title' => 'Title',

                'address.street' => 'Street (Address)',
                'address.city' => 'City (Address)',
                'address.state' => 'State (Address)',
                'address.postal_code' => 'Zip Code (Address)',
                'address.country' => 'Country (Address)',

                'details' => 'Description',

                'phone_numbers.work' => 'Work Phone',
                'phone_numbers.mobile' => 'Mobile Phone',
                'phone_numbers.home' => 'Home Phone',
                'phone_numbers.other' => 'Other Phone',

                'socials.facebook' => 'Facebook',
                'socials.twitter' => 'Twitter',
                'socials.linkedin' => 'LinkedIn',
                'socials.youtube' => 'YouTube',
                'socials.instagram' => 'Instagram',
                'socials.quora' => 'Quora',

                'websites.work' => 'Work Website',
                'websites.personal' => 'Personal Website',
                'websites.other' => 'Other Website',
            ];
        }

        foreach ($core_fields as $_id => $_label) {

            $bucket[] = (new ContactFieldEntity())
                ->set_id($_id)
                ->set_name($_label)
                ->set_data_type(ContactFieldEntity::TEXT_FIELD);
        }

        if (fusewp_is_premium()) {

            try {

                $custom_field_option_ids = [];

                $custom_fields = $this->apiClass()->apiRequest('custom_field_definitions');

                if (is_array($custom_fields) && !empty($custom_fields)) {
                    $recordType = $list_id == 'leads' ? 'lead' : 'person';
                    foreach ($custom_fields as $field) {

                        if (isset($field->options) && is_array($field->options)) {
                            $custom_field_option_ids[$field->id] = wp_list_pluck($field->options, 'id');
                        }

                        if (in_array($recordType, $field->available_on)) {

                            switch ($field->data_type) {
                                case 'Date':
                                    $data_type = ContactFieldEntity::DATE_FIELD;
                                    break;
                                case 'MultiSelect':
                                    $data_type = ContactFieldEntity::MULTISELECT_FIELD;
                                    break;
                                case 'Percentage':
                                    $data_type = ContactFieldEntity::NUMBER_FIELD;
                                    break;
                                default:
                                    $data_type = ContactFieldEntity::TEXT_FIELD;
                            }

                            $bucket[] = (new ContactFieldEntity())
                                ->set_id('fwpcpcus_' . $field->id . '|' . $field->data_type)
                                ->set_name($field->name)
                                ->set_data_type($data_type);
                        }
                    }
                }

                if (!empty($custom_field_option_ids)) {
                    update_option('fusewp_copper_custom_field_option_ids', $custom_field_option_ids);
                }

            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
        }

        return $bucket;
    }

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
            'people' => esc_html__('People', 'fusewp'),
            'leads' => esc_html__('Leads', 'fusewp'),
        ];
    }

    /**
     * @return AuthiflyCopper
     * @throws \Exception
     */
    public function apiClass()
    {
        $access_token = fusewpVar($this->get_settings(), 'access_token');

        if (empty($access_token)) {
            throw new \Exception(__('Copper access token not found.', 'fusewp'));
        }

        $config = [
            // secret key and callback not needed but authifly requires they have a value hence the FUSEWP_OAUTH_URL constant and "__"
            'callback' => FUSEWP_OAUTH_URL,
            'keys' => ['key' => '_', 'secret' => '__'],
            'access_token' => $access_token
        ];

        return new AuthiflyCopper($config, null, new OAuthCredentialStorage());
    }
}