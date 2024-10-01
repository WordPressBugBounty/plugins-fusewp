<?php

namespace FuseWP\Core\Integrations\Omnisend;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Omnisend extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'omnisend';

        $this->title = 'Omnisend';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/omnisend-integration.svg';

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

            return ! empty(fusewpVar($settings, 'api_key'));
        });
    }

    public function connection_settings()
    {
        $html = '';

        if ($this->is_connected()) {
            $html .= sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'));
        }

        $html .= '<form method="post">';
        $html .= sprintf(
            '<p><label for="fusewp-omnisend-api-key">%s</label> <input placeholder="%s" id="fusewp-omnisend-api-key" class="regular-text" type="password" name="fusewp-omnisend-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
        '<p class="regular-text">%s</p>',
        sprintf(
            __('Log in to your %sOmnisend Account%s to get your api key.', 'fusewp'),
            '<a target="_blank" href="https://app.omnisend.com/integrations/api-keys">',
            '</a>')
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_omnisend_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        return [];
    }

    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        $bucket[] = (new ContactFieldEntity())
            ->set_id('firstName')
            ->set_name(esc_html__('First Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('lastName')
            ->set_name(esc_html__('Last Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        if (fusewp_is_premium()) {
            $fields = [
                'address'     => 'Address',
                'birthdate'   => 'Birth Date',
                'city'        => 'City',
                'country'     => 'Country',
                'countryCode' => 'Country Code',
                'gender'      => 'Gender',
                'postalCode'  => 'Postal Code',
                'state'       => 'State',
            ];

            foreach ($fields as $k => $v) {

                $data_type = ContactFieldEntity::TEXT_FIELD;

                if ('birthdate' == $k) {
                    $data_type = ContactFieldEntity::DATE_FIELD;
                }

                $bucket[] = (new ContactFieldEntity())
                    ->set_id($k)
                    ->set_name($v)
                    ->set_data_type($data_type);
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
        if (isset($_POST['fusewp_omnisend_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                       = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key'] = sanitize_text_field($_POST['fusewp-omnisend-api-key']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }

    /**
     * @return APIClass
     *
     * @throws \Exception
     */
    public function apiClass($version = null)
    {
        $api_key = fusewpVar($this->get_settings(), 'api_key');

        if (empty($api_key)) {
            throw new \Exception(__('Omnisend API Key not found.', 'fusewp'));
        }

        return new APIClass($api_key, $version);
    }
}
