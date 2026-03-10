<?php

namespace FuseWP\Core\Integrations\BentoNow;

use Exception;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class BentoNow extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'bentonow';

        $this->title = 'Bento';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/bentonow-integration.png';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_bentonow_save_settings'])) {
            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                               = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['publishable_key'] = sanitize_text_field($_POST['fusewp-bentonow-publishable-key']);
                $old_data[$this->id]['secret_key']      = sanitize_text_field($_POST['fusewp-bentonow-secret-key']);
                $old_data[$this->id]['site_uuid']       = sanitize_text_field($_POST['fusewp-bentonow-site-uuid']);
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
            '<p><label for="fusewp-bentonow-publishable-key">%s</label> <input placeholder="%s" id="fusewp-bentonow-publishable-key" class="regular-text" type="password" name="fusewp-bentonow-publishable-key" value="%s"></p>',
            esc_html__('Publishable Key', 'fusewp'),
            esc_html__('Enter Publishable Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'publishable_key'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-bentonow-secret-key">%s</label> <input placeholder="%s" id="fusewp-bentonow-secret-key" class="regular-text" type="password" name="fusewp-bentonow-secret-key" value="%s"></p>',
            esc_html__('Secret Key', 'fusewp'),
            esc_html__('Enter Secret Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'secret_key'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-bentonow-site-uuid">%s</label> <input placeholder="%s" id="fusewp-bentonow-site-uuid" class="regular-text" type="text" name="fusewp-bentonow-site-uuid" value="%s"></p>',
            esc_html__('Site UUID', 'fusewp'),
            esc_html__('Enter Site UUID', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'site_uuid'))
        );
        $html .= sprintf(
            '<p class="regular-text">%s</p>',
            sprintf(
                __('Get your API credentials from %sSettings → API Keys%s in your Bento account.', 'fusewp'),
                '<a target="_blank" href="https://app.bentonow.com">',
                '</a>'
            )
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_bentonow_save_settings" value="%s"></form>',
            esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'publishable_key')) &&
                   ! empty(fusewpVar($settings, 'secret_key')) &&
                   ! empty(fusewpVar($settings, 'site_uuid'));
        });
    }

    /**
     * @inheritDoc
     * BentoNow uses tags for segmentation instead of traditional lists
     */
    public function get_email_list()
    {
        $tag_array = [];

        try {
            $response = $this->apiClass()->make_request('fetch/tags');
            $tags     = $response['body']['data'] ?? [];

            if (is_array($tags) && ! empty($tags)) {
                foreach ($tags as $tag) {
                    $tag_name = $tag['attributes']['name'] ?? '';
                    if ( ! empty($tag_name)) {
                        // Use tag name as both key and value since BentoNow uses tags for segmentation
                        $tag_array[$tag_name] = $tag_name;
                    }
                }
            }
        } catch (Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $tag_array;
    }

    /**
     * @return APIClass
     *
     * @throws Exception
     */
    public function apiClass()
    {
        $publishable_key = fusewpVar($this->get_settings(), 'publishable_key');
        $secret_key      = fusewpVar($this->get_settings(), 'secret_key');
        $site_uuid       = fusewpVar($this->get_settings(), 'site_uuid');

        if (empty($publishable_key)) {
            throw new Exception(__('Bento Publishable Key not found.', 'fusewp'));
        }

        if (empty($secret_key)) {
            throw new Exception(__('Bento Secret Key not found.', 'fusewp'));
        }

        if (empty($site_uuid)) {
            throw new Exception(__('Bento Site UUID not found.', 'fusewp'));
        }

        return new APIClass($publishable_key, $secret_key, $site_uuid);
    }

    /**
     * @inheritDoc
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        if (fusewp_is_premium()) {

            try {
                $response = $this->apiClass()->make_request('fetch/fields');
                $fields   = $response['body']['data'] ?? [];

                if (is_array($fields) && ! empty($fields)) {

                    foreach ($fields as $field) {
                        $field_key  = $field['attributes']['key'] ?? '';
                        $field_name = $field['attributes']['name'] ?? $field_key;

                        if (empty($field_key)) {
                            continue;
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($field_key)
                            ->set_name($field_name)
                            ->set_data_type(ContactFieldEntity::TEXT_FIELD);
                    }
                }
            } catch (Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
        }

        // Ensure default fields are available
        $common_fields = [
            'first_name' => 'First Name',
            'last_name'  => 'Last Name',
            'phone'      => 'Phone',
            'company'    => 'Company',
        ];

        foreach ($common_fields as $key => $name) {
            // Check if field already exists
            $exists = false;
            foreach ($bucket as $field) {
                if ($field->id === $key) {
                    $exists = true;
                    break;
                }
            }

            if ( ! $exists) {
                $bucket[] = (new ContactFieldEntity())
                    ->set_id($key)
                    ->set_name($name)
                    ->set_data_type(ContactFieldEntity::TEXT_FIELD);
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
}
