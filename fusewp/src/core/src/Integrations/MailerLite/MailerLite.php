<?php

namespace FuseWP\Core\Integrations\MailerLite;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class MailerLite extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'mailerlite';

        $this->title = 'MailerLite';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/mailerlite-integration.svg';

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

            return ! empty(fusewpVar($settings, 'api_token'));
        });
    }

    /**
     * {@inheritDoc}
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        // not restricting this to premium because first/last names can varies based on MailerLite account language and not separately passed to MailerLite api

        try {

            $response = $this->apiClass()->make_request('fields', ['limit' => 100]);

            if (isset($response['body']['data'])) {

                foreach ($response['body']['data'] as $customField) {

                    switch ($customField['type']) {
                        case 'date':
                            $data_type = ContactFieldEntity::DATE_FIELD;
                            break;
                        case 'number':
                            $data_type = ContactFieldEntity::NUMBER_FIELD;
                            break;
                        default:
                            $data_type = ContactFieldEntity::TEXT_FIELD;
                    }

                    $bucket[] = (new ContactFieldEntity())
                        ->set_id($customField['key'])
                        ->set_name($customField['name'])
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
        if (isset($_POST['fusewp_mailerlite_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                         = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_token'] = sanitize_text_field($_POST['fusewp-mailerlite-api-token']);
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
            '<p><label for="fusewp-mailerlite-api-token">%s</label> <input placeholder="%s" id="fusewp-mailerlite-api-token" class="regular-text" type="password" name="fusewp-mailerlite-api-token" value="%s"></p>',
            esc_html__('API Token', 'fusewp'),
            esc_html__('Enter API Token', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_token'))
        );
        $html .= sprintf(
        '<p class="regular-text">%s</p>',
        sprintf(
            __('Log in to your %sMailerLite account%s to get your API Key.', 'fusewp'),
            '<a target="_blank" href="https://dashboard.mailerlite.com/integrations/api">',
            '</a>')
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_mailerlite_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        $lists_array = [];

        try {

            $page  = 1;
            $loop  = true;
            $limit = 100;

            while ($loop === true) {

                $allGroups = $this->apiClass()->make_request('groups', ['limit' => $limit, 'page' => $page]);

                if (isset($allGroups['body']['data'])) {

                    foreach ($allGroups['body']['data'] as $list) {
                        $lists_array[$list['id']] = $list['name'];
                    }

                    if (count($allGroups['body']['data']) < $limit) {
                        $loop = false;
                    }

                    $page++;

                } else {
                    $loop = false;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $lists_array;
    }

    /**
     * @return APIClass
     *
     * @throws \Exception
     */
    public function apiClass()
    {
        $api_token = fusewpVar($this->get_settings(), 'api_token');

        if (empty($api_token)) {
            throw new \Exception(__('MailerLite API Token not found.', 'fusewp'));
        }

        return new APIClass($api_token);
    }
}
