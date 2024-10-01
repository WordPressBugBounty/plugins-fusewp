<?php

namespace FuseWP\Core\Integrations\Klaviyo;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Klaviyo extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'klaviyo';

        $this->title = 'Klaviyo';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/klaviyo-integration.png';

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

    /**
     * {@inheritDoc}
     */
    public function get_contact_fields($list_id = '')
    {
        $merge_fields_array = apply_filters('fusewp_klaviyo_custom_fields', [
            '$first_name'   => __('First Name', 'fusewp'),
            '$last_name'    => __('Last Name', 'fusewp'),
            '$phone_number' => __('Phone Number', 'fusewp'),
            '$title'        => __('Job Title', 'fusewp'),
            '$organization' => __('Organization Name', 'fusewp'),
            '$address1'     => __('Street address 1', 'fusewp'),
            '$address2'     => __('Street address 2', 'fusewp'),
            '$city'         => __('City', 'fusewp'),
            '$region'       => __('State / Province', 'fusewp'),
            '$country'      => __('Country', 'fusewp'),
            '$zip'          => __('ZIP or Postal Code', 'fusewp'),
            '$image'        => __('Profile Image URL', 'fusewp')
        ]);

        $bucket = [];

        foreach ($merge_fields_array as $customFieldId => $customField) {

            $bucket[] = (new ContactFieldEntity())
                ->set_id($customFieldId)
                ->set_name($customField)
                ->set_data_type(ContactFieldEntity::TEXT_FIELD);
        }

        return $bucket;
    }

    public function get_sync_action()
    {
        return new SyncAction($this);
    }

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_klaviyo_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                       = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key'] = sanitize_text_field($_POST['fusewp-klaviyo-api-key']);
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
            '<p><label for="fusewp-klaviyo-api-key">%s</label> <input placeholder="%s" id="fusewp-klaviyo-api-key" class="regular-text" type="password" name="fusewp-klaviyo-api-key" value="%s"></p>',
            esc_html__('Private API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
        '<p class="regular-text">%s</p>',
        sprintf(
            __('Log in to your %sKlaviyo account%s to get your API key.', 'fusewp'),
            '<a target="_blank" href="https://www.klaviyo.com/settings/account/api-keys">',
            '</a>')
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_klaviyo_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        $bucket = [];

        try {

            return $this->apiClass()->get_lists();

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
            throw new \Exception(__('Klaviyo API Key not found.', 'fusewp'));
        }

        return new APIClass($api_key);
    }
}
