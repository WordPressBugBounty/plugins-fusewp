<?php

namespace FuseWP\Core\Integrations\OrttoCRM;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class OrttoCRM extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'orttocrm';

        $this->title = 'Ortto';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/ortto-integration.svg';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);
    }

    /**
     * @return mixed
     */
    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    /**
     * @return mixed
     */
    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'api_key'));
        });
    }

    /**
     * @return mixed
     */
    public function connection_settings()
    {
        $html = '';

        if ($this->is_connected()) {
            $html .= sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'));
        }

        $html .= '<form method="post">';
        $html .= sprintf(
            '<p><label for="fusewp-orttocrm-api-key">%s</label> <input placeholder="%s" id="fusewp-orttocrm-api-key" class="regular-text" type="password" name="fusewp-orttocrm-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );

        $saved_region = fusewpVar($this->get_settings(), 'region');

        $html .= sprintf(
            '<p><label for="fusewp-orttocrm-region">%s</label> <select id="fusewp-orttocrm-region" class="regular-text" name="fusewp-orttocrm-region">
                <option value="eu" %s>%s</option>
                <option value="au" %s>%s</option>
                <option value="others" %s>%s</option>
            </select></p>',
            esc_html__('Region', 'fusewp'),
            selected($saved_region, 'eu', false),
            esc_html__('Europe', 'fusewp'),
            selected($saved_region, 'au', false),
            esc_html__('Australia', 'fusewp'),
            selected($saved_region, 'others', false),
            esc_html__('Rest of the World', 'fusewp')
        );

        $html .= sprintf(
            '<p>%s</p>',
            sprintf(
                __('Log in to your Ortto account to get your API key and know your region. %sLearn more%s.', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/connect-wordpress-to-ortto/">',
                '</a>'
            )
        );

        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_orttocrm_save_settings" value="%s"></form>',
            esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        return [];
    }

    /**
     * @return array
     */
    public function get_organization_list()
    {
        $list = [];

        try {

            $response = $this->apiClass()->post('organizations/get', [
                'limit'            => 1000,
                'sort_by_field_id' => 'str:o:name',
                'sort_order'       => 'asc',
                'offset'           => 0,
                'fields'           => ['str:o:name', 'str:o:industry']
            ]);

            $organizations = $response['body']['organizations'] ?? [];

            if ( ! empty($organizations) && is_array($organizations)) {
                foreach ($organizations as $organization) {
                    $list[$organization['id']] = $organization['fields']['str:o:name'] ?? '';
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list;
    }

    /**
     * @param $list_id
     *
     * @return ContactFieldEntity[]
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        $default_fields = [
            'str::first'    => esc_html__('First name', 'fusewp'),
            'str::last'     => esc_html__('Last name', 'fusewp'),
            'phn::phone'    => esc_html__('Phone number', 'fusewp'),
            'str::language' => esc_html__('Language', 'fusewp'),
            'geo::country'  => esc_html__('Country', 'fusewp'),
            'geo::region'   => esc_html__('Region', 'fusewp'),
            'geo::city'     => esc_html__('City', 'fusewp'),
            'str::postal'   => esc_html__('Postal code', 'fusewp'),
            'dtz::b'        => esc_html__('Birthday', 'fusewp'),
        ];

        foreach ($default_fields as $key => $value) {
            $data_type = ContactFieldEntity::TEXT_FIELD;

            if (strpos($key, 'dtz::') === 0) {
                $data_type = ContactFieldEntity::DATE_FIELD;
            }

            $bucket[] = (new ContactFieldEntity())
                ->set_id($key)
                ->set_name($value)
                ->set_data_type($data_type);
        }

        if (fusewp_is_premium()) {

            try {

                $response = $this->apiClass()->post('person/custom-field/get');

                $fields = $response['body']['fields'] ?? [];

                if ( ! empty($fields) && is_array($fields)) {

                    foreach ($fields as $item) {

                        $field = $item['field'] ?? [];

                        switch ($field['display_type']) {
                            case 'decimal':
                            case 'integer':
                                $data_type = ContactFieldEntity::NUMBER_FIELD;
                                break;
                            case 'date':
                                $data_type = ContactFieldEntity::DATE_FIELD;
                                break;
                            case 'time':
                                $data_type = ContactFieldEntity::DATETIME_FIELD;
                                break;
                            case 'multi_select':
                                $data_type = ContactFieldEntity::MULTISELECT_FIELD;
                                break;
                            case 'bool':
                                $data_type = ContactFieldEntity::BOOLEAN_FIELD;
                                break;
                            default:
                                $data_type = ContactFieldEntity::TEXT_FIELD;
                        }


                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($field['id'])
                            ->set_name($field['name'])
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

    /**
     * @return void
     */
    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_orttocrm_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                       = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key'] = sanitize_text_field($_POST['fusewp-orttocrm-api-key']);
                $old_data[$this->id]['region']  = sanitize_text_field($_POST['fusewp-orttocrm-region']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }

    /**
     * Creates and returns an instance of the APIClass using the settings provided.
     *
     * @return APIClass Returns an instance of APIClass initialized with the API key and region.
     * @throws \Exception Throws an exception if the API key is not found in the settings.
     */
    public function apiClass()
    {
        $api_key = fusewpVar($this->get_settings(), 'api_key');
        $region  = fusewpVar($this->get_settings(), 'region');

        if (empty($api_key)) {
            throw new \Exception(__('Ortto API Key not found.', 'fusewp'));
        }

        return new APIClass($api_key, $region);
    }

    public static function get_instance()
    {
        if (fusewp_is_premium()) {
            return new self();
        }

        return false;
    }
}
