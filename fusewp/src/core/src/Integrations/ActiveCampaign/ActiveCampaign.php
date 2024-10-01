<?php

namespace FuseWP\Core\Integrations\ActiveCampaign;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class ActiveCampaign extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'activecampaign';

        $this->title = 'ActiveCampaign';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/activecampaign-integration.png';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'api_url')) && ! empty(fusewpVar($settings, 'api_key'));
        });
    }

    public function set_bulk_sync_throttle_seconds($seconds)
    {
        return 1;
    }

    /**
     * {@inheritDoc}
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpFirstName')
            ->set_name(esc_html__('First Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpLastName')
            ->set_name(esc_html__('Last Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpPhone')
            ->set_name(esc_html__('Phone', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpJobTitle')
            ->set_name(esc_html__('Job Title', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        if (fusewp_is_premium()) {

            try {

                $fields = $this->apiClass()->make_request('fields', ['limit' => 1000]);

                if (isset($fields['body']->fields)) {

                    foreach ($fields['body']->fields as $field) {

                        switch ($field->type) {
                            case 'date':
                                $data_type = ContactFieldEntity::DATE_FIELD;
                                break;
                            case 'datetime':
                                $data_type = ContactFieldEntity::DATETIME_FIELD;
                                break;
                            case 'listbox':
                                $data_type = ContactFieldEntity::MULTISELECT_FIELD;
                                break;
                            default:
                                $data_type = ContactFieldEntity::TEXT_FIELD;
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($field->id)
                            ->set_name($field->title)
                            ->set_data_type($data_type)
                            ->set_is_required($field->isrequired == '1');
                    }
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

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_activecampaign_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                       = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_url'] = sanitize_text_field($_POST['fusewp-activecampaign-api-url']);
                $old_data[$this->id]['api_key'] = sanitize_text_field($_POST['fusewp-activecampaign-api-key']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }

    public function connection_settings()
    {
        $html = '';

        if ($this->is_connected()) {
            $html .= sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'));
        }

        $html .= '<form method="post">';
        $html .= sprintf(
            '<p><label for="fusewp-activecampaign-api-url">%s</label> <input placeholder="%s" id="fusewp-activecampaign-api-url" class="regular-text" type="text" name="fusewp-activecampaign-api-url" value="%s"></p>',
            esc_html__('API URL', 'fusewp'),
            esc_html__('Enter API URL', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_url'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-activecampaign-api-key">%s</label> <input placeholder="%s" id="fusewp-activecampaign-api-key" class="regular-text" type="password" name="fusewp-activecampaign-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
        '<p class="regular-text">%s</p>',
        sprintf(
            __('Log in to your %sActiveCampaign account%s to get your API URL  and API Key at the "Developer" tab in account settings.', 'fusewp'),
            '<a target="_blank" href="https://www.activecampaign.com/login/">',
            '</a>')
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_activecampaign_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        $bucket = [];

        try {

            $lists = $this->apiClass()->make_request('/lists', ['limit' => 1000]);

            if (isset($lists['body']->lists)) {
                foreach ($lists['body']->lists as $list) {
                    $bucket[$list->id] = $list->name;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

    /**
     * @return APIClass
     *
     * @throws \Exception
     */
    public function apiClass()
    {
        $api_key = fusewpVar($this->get_settings(), 'api_key');

        if (empty($api_key)) {
            throw new \Exception(__('ActiveCampaign API Key not found.', 'fusewp'));
        }

        $api_url = fusewpVar($this->get_settings(), 'api_url');

        if (empty($api_url)) {
            throw new \Exception(__('ActiveCampaign API URL not found.', 'fusewp'));
        }

        return new APIClass($api_key, $api_url);
    }
}
