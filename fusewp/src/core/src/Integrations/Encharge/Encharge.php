<?php

namespace FuseWP\Core\Integrations\Encharge;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Encharge extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'encharge';

        $this->title = 'Encharge';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/encharge-integration.svg';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);
    }

    /**
     * @return array
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
            '<p><label for="fusewp-encharge-api-key">%s</label> <input placeholder="%s" id="fusewp-encharge-api-key" class="regular-text" type="password" name="fusewp-encharge-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
            '<p class="regular-text">%s</p>',
            sprintf(
                __('Log in to your %sEncharge account%s to get your api key.', 'mailoptin'),
                '<a target="_blank" href="https://app.encharge.io/account/info">',
                '</a>'
            )
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_encharge_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    /**
     * @return mixed
     */
    public function get_email_list()
    {
        $list_array = [];
        try {
            $response = $this->apiClass()->make_request('/tags-management');
            $tags     = $response['body']->tags ?? [];

            if ( ! empty($tags)) {
                foreach ($tags as $item) {
                    $list_array[$item->tag] = $item->tag;
                }
            }
        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list_array;
    }

    /**
     * @param $list_id
     *
     * @return array
     */
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

            try {

                $response = $this->apiClass()->make_request('/fields');

                if ( ! empty($response['body']->items)) {
                    foreach ($response['body']->items as $field) {
                        if (
                            in_array($field->name, ['firstName', 'lastName', 'email', 'name', 'userId']) ||
                            empty($field->title) || $field->readOnly === true
                        ) {
                            continue;
                        }

                        $data_type = ContactFieldEntity::TEXT_FIELD;

                        if (isset($field->format) && $field->format === 'date-time') {
                            $data_type = ContactFieldEntity::DATETIME_FIELD;
                        }

                        if (in_array($field->type, ['number', 'integer'])) {
                            $data_type = ContactFieldEntity::NUMBER_FIELD;
                        }

                        if ($field->type === 'boolean') {
                            $data_type = ContactFieldEntity::BOOLEAN_FIELD;
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($field->name)
                            ->set_name($field->title)
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

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_encharge_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                       = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key'] = sanitize_text_field($_POST['fusewp-encharge-api-key']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }

    public function apiClass()
    {
        $api_key = fusewpVar($this->get_settings(), 'api_key');

        if (empty($api_key)) {
            throw new \Exception(__('Encharge API Key not found.', 'fusewp'));
        }

        return new APIClass($api_key);
    }
}
