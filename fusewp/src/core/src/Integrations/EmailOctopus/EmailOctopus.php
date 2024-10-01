<?php

namespace FuseWP\Core\Integrations\EmailOctopus;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class EmailOctopus extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'emailoctopus';

        $this->title = 'EmailOctopus';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/emailoctopus-integration.svg';

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
            '<p><label for="fusewp-emailoctopus-api-key">%s</label> <input placeholder="%s" id="fusewp-emailoctopus-api-key" class="regular-text" type="password" name="fusewp-emailoctopus-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
        '<p class="regular-text">%s</p>',
        sprintf(
            __('Log in to your %1$sEmailOctopus account%3$s and visit the %2$sAPI%3$s page to get your API Key.', 'fusewp'),
            '<a target="_blank" href="https://emailoctopus.com/account/sign-in">',
            '<a target="_blank" href="https://emailoctopus.com/api-documentation/">',
            '</a>')
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_emailoctopus_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        $list_array = [];

        try {

            $response = $this->apiClass()->make_request('lists');
            $lists    = $response['body']['data'];

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

    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        try {
            $response = $this->apiClass()->make_request(sprintf('lists/%s', $list_id));

            if ( ! empty($response['body']['fields']) && is_array($response['body']['fields'])) {
                foreach ($response['body']['fields'] as $field) {

                    // skip custom fields if lite
                    if ( ! fusewp_is_premium() && ! in_array($field['tag'], ['FirstName', 'LastName'])) continue;

                    switch ($field['type']) {
                        case 'DATE':
                            $data_type = ContactFieldEntity::DATE_FIELD;
                            break;
                        case 'NUMBER':
                            $data_type = ContactFieldEntity::NUMBER_FIELD;
                            break;
                        default:
                            $data_type = ContactFieldEntity::TEXT_FIELD;
                    }

                    $bucket[] = (new ContactFieldEntity())
                        ->set_id($field['tag'])
                        ->set_name($field['label'])
                        ->set_data_type($data_type);
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
        if (isset($_POST['fusewp_emailoctopus_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                       = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key'] = sanitize_text_field($_POST['fusewp-emailoctopus-api-key']);
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
    public function apiClass()
    {
        $api_key = fusewpVar($this->get_settings(), 'api_key');

        if (empty($api_key)) {
            throw new \Exception(__('EmailOctopus API Key not found.', 'fusewp'));
        }

        return new APIClass($api_key);
    }
}
