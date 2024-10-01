<?php

namespace FuseWP\Core\Integrations\Mailjet;

use Exception;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Mailjet extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'mailjet';

        $this->title = 'Mailjet';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/mailjet-integration.svg';

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

            return ! empty(fusewpVar($settings, 'api_key')) && ! empty(fusewpVar($settings, 'secret_key'));
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
            '<p><label for="fusewp-mailjet-api-key">%s</label> <input placeholder="%s" id="fusewp-mailjet-api-key" class="regular-text" type="password" name="fusewp-mailjet-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-mailjet-secret-key">%s</label> <input placeholder="%s" id="fusewp-mailjet-secret-key" class="regular-text" type="password" name="fusewp-mailjet-secret-key" value="%s"></p>',
            esc_html__('Secret Key', 'fusewp'),
            esc_html__('Enter Secret Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'secret_key'))
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_mailjet_save_settings" value="%s"></form>',
            esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    /**
     * @inheritDoc
     */
    public function get_email_list()
    {
        $list_array = [];

        try {

            $response = $this->apiClass()->make_request('contactslist', ['Limit' => 1000]);

            $contactsList = $response['body']['Data'] ?? [];

            if ( ! empty($contactsList)) {
                foreach ($contactsList as $contact) {
                    $list_array[$contact['ID']] = $contact['Name'];
                }
            }
        } catch (Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list_array;
    }

    /**
     * @inheritDoc
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        $bucket[] = (new ContactFieldEntity())
            ->set_id('firstname')
            ->set_name('firstname')
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('name')
            ->set_name('name')
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        if (fusewp_is_premium()) {

            try {

                // https://dev.mailjet.com/email/reference/contacts/contact-properties/
                $response = $this->apiClass()->make_request('contactmetadata', ['Limit' => 1000]);

                if (isset($response['body']['Data']) && is_array($response['body']['Data'])) {

                    foreach ($response['body']['Data'] as $customField) {

                        if (in_array($customField['Name'], ['firstname', 'name'])) continue;

                        $data_type = ContactFieldEntity::TEXT_FIELD;

                        if (isset($customField['Datatype'])) {

                            switch ($customField['Datatype']) {
                                case 'datetime':
                                    $data_type = ContactFieldEntity::DATETIME_FIELD;
                                    break;
                                case 'int':
                                    $data_type = ContactFieldEntity::NUMBER_FIELD;
                                    break;
                                case 'bool':
                                    $data_type = ContactFieldEntity::BOOLEAN_FIELD;
                                    break;
                            }
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($customField['Name'])
                            ->set_name($customField['Name'])
                            ->set_data_type($data_type);
                    }
                }

            } catch (Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
        }

        return $bucket;
    }

    /**
     * @inheritDoc
     */
    public function get_sync_action()
    {
        return new SyncAction($this);
    }

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_mailjet_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                          = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key']    = sanitize_text_field($_POST['fusewp-mailjet-api-key']);
                $old_data[$this->id]['secret_key'] = sanitize_text_field($_POST['fusewp-mailjet-secret-key']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }

    /**
     * @return APIClass
     * @throws Exception
     */
    public function apiClass()
    {
        $api_key    = fusewpVar($this->get_settings(), 'api_key');
        $secret_key = fusewpVar($this->get_settings(), 'secret_key');

        if (empty($api_key)) {
            throw new Exception(__('Mailjet API Key not found.', 'fusewp'));
        }

        if (empty($secret_key)) {
            throw new Exception(__('Mailjet Secret Key not found.', 'fusewp'));
        }

        return new APIClass($api_key, $secret_key);
    }
}
