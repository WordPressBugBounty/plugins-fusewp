<?php

namespace FuseWP\Core\Integrations\Sender;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Sender extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'sender';

        $this->title = 'Sender';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/sender-integration.svg';

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

            return ! empty(fusewpVar($settings, 'api_token'));
        });
    }

    public function set_bulk_sync_throttle_seconds($seconds)
    {
        return 1;
    }

    /**
     * @return string
     */
    public function connection_settings()
    {
        $html = '';

        if ($this->is_connected()) {
            $html .= sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'));
        }

        $html .= '<form method="post">';
        $html .= sprintf(
            '<p><label for="fusewp-sender-api-token">%s</label> <input placeholder="%s" id="fusewp-sender-api-token" class="regular-text" type="password" name="fusewp-sender-api-token" value="%s"></p>',
            esc_html__('API Token', 'fusewp'),
            esc_html__('Enter API Token', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_token'))
        );
        $html .= sprintf(
            '<p class="regular-text">%s</p>',
            sprintf(
                __('Log in to your %sSender.net account%s to get your API token from Settings > API access tokens.', 'fusewp'),
                '<a target="_blank" href="https://app.sender.net/settings/tokens">',
                '</a>'
            )
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_sender_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    /**
     * @return array
     */
    public function get_email_list()
    {
        $bucket = [];

        try {

            $response = $this->apiClass()->make_request('groups', ['limit' => 1000]);

            if (isset($response['body']->data) && is_array($response['body']->data)) {
                foreach ($response['body']->data as $group) {
                    $bucket[$group->id] = $group->title;
                }
            }

            return $bucket;

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
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
            ->set_id('firstname')
            ->set_name(esc_html__('First Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('lastname')
            ->set_name(esc_html__('Last Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        if (fusewp_is_premium()) {

            try {
                $response = $this->apiClass()->make_request('fields');

                if (isset($response['body']->data) && is_array($response['body']->data)) {

                    foreach ($response['body']->data as $field) {

                        $key = preg_replace('/^{{(.+)}}$/', '$1', $field->name);

                        if (in_array($key, ['firstname', 'lastname', 'email'])) continue;

                        $datatype = ContactFieldEntity::TEXT_FIELD;

                        switch ($field->type) {
                            case 'datetime':
                                $datatype = ContactFieldEntity::DATETIME_FIELD;
                                break;
                            case 'date':
                                $datatype = ContactFieldEntity::DATE_FIELD;
                                break;
                            case 'number':
                                $datatype = ContactFieldEntity::NUMBER_FIELD;
                                break;
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($key)
                            ->set_name($field->title)
                            ->set_data_type($datatype);
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
        if (isset($_POST['fusewp_sender_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                         = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_token'] = sanitize_text_field($_POST['fusewp-sender-api-token']);
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
        $api_token = fusewpVar($this->get_settings(), 'api_token');

        if (empty($api_token)) {
            throw new \Exception(__('Sender.net API Token not found.', 'fusewp'));
        }

        return new APIClass($api_token);
    }
}
