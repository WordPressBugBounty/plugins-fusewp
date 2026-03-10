<?php

namespace FuseWP\Core\Integrations\Mailercloud;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Mailercloud extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'mailercloud';

        $this->title = 'Mailercloud';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/mailercloud-integration.svg';

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
        $bucket = [];

        try {

            $page   = 1;
            $limit  = 100;
            $status = true;

            while (true === $status) {

                $response = $this->apiClass()->post('contact/property/search', [
                    'limit'  => $limit,
                    'page'   => $page,
                    'search' => ''
                ]);

                if (isset($response['body']->data) && is_array($response['body']->data)) {

                    foreach ($response['body']->data as $customField) {

                        $field_value = $customField->field_value;
                        $field_name  = $customField->field_name;

                        $data_type = ContactFieldEntity::TEXT_FIELD;

                        if (isset($customField->field_type)) {
                            switch ($customField->field_type) {
                                case 'Date':
                                    $data_type = ContactFieldEntity::DATE_FIELD;
                                    break;
                                case 'Number':
                                    $data_type = ContactFieldEntity::NUMBER_FIELD;
                                    break;
                            }
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($field_value)
                            ->set_name($field_name)
                            ->set_data_type($data_type);
                    }

                    if (count($response['body']->data) < $limit) {
                        $status = false;
                    }

                    $page++;
                } else {
                    $status = false;
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
        if (isset($_POST['fusewp_mailercloud_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                       = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key'] = sanitize_text_field($_POST['fusewp-mailercloud-api-key']);
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
            '<p><label for="fusewp-mailercloud-api-key">%s</label> <input placeholder="%s" id="fusewp-mailercloud-api-key" class="regular-text" type="password" name="fusewp-mailercloud-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
            '<p class="regular-text">%s</p>',
            sprintf(
                __('Log in to your %sMailercloud account%s to get your API Key.', 'fusewp'),
                '<a target="_blank" href="https://app.mailercloud.com/account/api-integrations">',
                '</a>')
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_mailercloud_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id . '_get_email_list', function () {

            $list_array = [];

            try {

                $page   = 1;
                $limit  = 100;
                $status = true;

                while (true === $status) {

                    $response = $this->apiClass()->post('lists/search', [
                        'limit'       => $limit,
                        'list_type'   => 1,
                        'page'        => $page,
                        'search_name' => '',
                        'sort_field'  => 'name',
                        'sort_order'  => 'asc'
                    ]);

                    if (isset($response['body']->data) && is_array($response['body']->data)) {
                        foreach ($response['body']->data as $list) {
                            $list_array[$list->id] = $list->name;
                        }

                        if (count($response['body']->data) < $limit) {
                            $status = false;
                        }

                        $page++;
                    } else {
                        $status = false;
                    }
                }

            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }

            return $list_array;
        });
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
            throw new \Exception(__('Mailercloud API Key not found.', 'fusewp'));
        }

        return new APIClass($api_key);
    }
}
