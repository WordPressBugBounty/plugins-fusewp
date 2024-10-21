<?php

namespace FuseWP\Core\Integrations\ConvertKit;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class ConvertKit extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'convertkit';

        $this->title = 'ConvertKit';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/convertkit-integration.svg';

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

            return ! empty(fusewpVar($settings, 'api_secret'));
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
            ->set_name(esc_html__('First Name', 'fusewp'));

        try {

            $response = $this->apiClass()->get_custom_fields();

            if (isset($response['body']->custom_fields) && is_array($response['body']->custom_fields)) {

                foreach ($response['body']->custom_fields as $customField) {

                    $bucket[] = (new ContactFieldEntity())
                        ->set_id($customField->key)
                        ->set_name($customField->label)
                        ->set_data_type(ContactFieldEntity::TEXT_FIELD);
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

    public function get_sync_action()
    {
        return new SyncAction($this);
    }

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_convertkit_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                          = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_secret'] = sanitize_text_field($_POST['fusewp-convertkit-api-secret']);
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
            '<p><label for="fusewp-convertkit-api-secret">%s</label> <input placeholder="%s" id="fusewp-convertkit-api-secret" class="regular-text" type="password" name="fusewp-convertkit-api-secret" value="%s"></p>',
            esc_html__('API Secret', 'fusewp'),
            esc_html__('Enter API Secret', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_secret'))
        );
        $html .= sprintf(
        '<p class="regular-text">%s</p>',
        sprintf(
            __('Log in to your %sKit (ConvertKit) account%s to get your api key.', 'fusewp'),
            '<a target="_blank" href="https://app.kit.com/account_settings/developer_settings">',
            '</a>')
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_convertkit_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        $form_array = [];

        try {

            $response = $this->apiClass()->get_forms();

            if (isset($response['body']->forms) && is_array($response['body']->forms)) {

                foreach ($response['body']->forms as $form) {
                    $form_array[$form->id] = $form->name;
                }
            }

            return $form_array;

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $form_array;
    }

    /**
     * @return APIClass
     *
     * @throws \Exception
     */
    public function apiClass()
    {
        $api_secret = fusewpVar($this->get_settings(), 'api_secret');

        if (empty($api_secret)) {
            throw new \Exception(__('Kit (ConvertKit) API Secret not found.', 'fusewp'));
        }

        return new APIClass($api_secret);
    }
}
