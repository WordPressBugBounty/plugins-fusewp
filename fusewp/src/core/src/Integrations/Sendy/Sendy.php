<?php

namespace FuseWP\Core\Integrations\Sendy;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Sendy extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'sendy';

        $this->title = 'Sendy';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/sendy-integration.png';

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

            return ! empty(fusewpVar($settings, 'api_key')) &&
                   ! empty(fusewpVar($settings, 'installation_url')) &&
                   ! empty(fusewpVar($settings, 'brand_id'));
        });
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
            ->set_id('fusewpCountry')
            ->set_name(esc_html__('Country', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpIPAddress')
            ->set_name(esc_html__('IP Address', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpReferrer')
            ->set_name(esc_html__('Referrer', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $custom_fields = apply_filters('fusewp_sendy_get_contact_fields', [], $list_id, $this);

        if (is_array($custom_fields) && ! empty($custom_fields)) {
            foreach ($custom_fields as $id => $label) {
                $bucket[] = (new ContactFieldEntity())
                    ->set_id($id)
                    ->set_name($label)
                    ->set_data_type(ContactFieldEntity::TEXT_FIELD);
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
        if (isset($_POST['fusewp_sendy_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                                = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key']          = sanitize_text_field($_POST['fusewp-sendy-api-key']);
                $old_data[$this->id]['installation_url'] = untrailingslashit(sanitize_text_field($_POST['fusewp-sendy-installation-url']));
                $old_data[$this->id]['brand_id']         = sanitize_text_field($_POST['fusewp-sendy-brand-id']);
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
            '<p><label for="fusewp-sendy-api-key">%s</label> <input placeholder="%s" id="fusewp-sendy-api-key" class="regular-text" type="password" name="fusewp-sendy-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-sendy-installation-url">%s</label> <input placeholder="%s" id="fusewp-sendy-installation-url" class="regular-text" type="text" name="fusewp-sendy-installation-url" value="%s"></p>',
            esc_html__('Installation URL', 'fusewp'),
            'https://',
            esc_attr(fusewpVar($this->get_settings(), 'installation_url'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-sendy-brand-id">%s</label> <input placeholder="%s" id="fusewp-sendy-brand-id" class="regular-text" type="text" name="fusewp-sendy-brand-id" value="%s"></p>',
            esc_html__('Brand ID', 'fusewp'),
            esc_html__('Enter Brand ID', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'brand_id'))
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_sendy_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        $list_array = [];

        try {

            $brand_id = fusewpVar($this->get_settings(), 'brand_id', '');

            $response = $this->apiClass()->post('api/lists/get-lists.php', ['brand_id' => intval($brand_id)]);

            $lists = json_decode($response['body'], true);

            if (is_array($lists) && ! empty($lists)) {

                foreach ($lists as $list) {
                    $list_array[$list['id']] = $list['name'];
                }

            } else {
                fusewp_log_error($this->id, __METHOD__ . ':' . $response['body']);
            }

            return $list_array;

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list_array;
    }

    /**
     * @return APIClass
     *
     * @throws \Exception
     */
    public function apiClass()
    {
        $installation_url = fusewpVar($this->get_settings(), 'installation_url');
        $brand_id         = fusewpVar($this->get_settings(), 'brand_id');
        $api_key          = fusewpVar($this->get_settings(), 'api_key');

        if (empty($api_key)) {
            throw new \Exception(__('Sendy API Key not found.', 'fusewp'));
        }

        if (empty($installation_url)) {
            throw new \Exception(__('Sendy installation URL not found.', 'fusewp'));
        }

        if (empty($brand_id)) {
            throw new \Exception(__('Sendy Brand ID not found.', 'fusewp'));
        }

        return new APIClass($installation_url, $api_key);
    }
}