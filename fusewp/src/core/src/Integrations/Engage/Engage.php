<?php

namespace FuseWP\Core\Integrations\Engage;

use Exception;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Engage extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'engage';

        $this->title = 'Engage.so';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/engage-integration.svg';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_engage_save_settings'])) {
            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                           = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['public_key']  = sanitize_text_field($_POST['fusewp-engage-public-key']);
                $old_data[$this->id]['private_key'] = sanitize_text_field($_POST['fusewp-engage-private-key']);
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
            '<p><label for="fusewp-engage-public-key">%s</label> <input placeholder="%s" id="fusewp-engage-public-key" class="regular-text" type="password" name="fusewp-engage-public-key" value="%s"></p>',
            esc_html__('Public Key', 'fusewp'),
            esc_html__('Enter Public Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'public_key'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-engage-private-key">%s</label> <input placeholder="%s" id="fusewp-engage-private-key" class="regular-text" type="password" name="fusewp-engage-private-key" value="%s"></p>',
            esc_html__('Private Key', 'fusewp'),
            esc_html__('Enter Private Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'private_key'))
        );
        $html .= sprintf(
            '<p class="regular-text">%s</p>',
            sprintf(
                __('Get your API credentials from %sEngage Dashboard >> Settings >> Organization%s.', 'fusewp'),
                '<strong>',
                '</strong>'
            )
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf(
            '<input type="submit" class="button-primary" name="fusewp_engage_save_settings" value="%s"></form>',
            esc_html__('Save Changes', 'fusewp')
        );

        return $html;
    }

    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'public_key')) &&
                   ! empty(fusewpVar($settings, 'private_key'));
        });
    }

    /**
     * @inheritDoc
     */
    public function get_email_list()
    {
        $list_array = [];

        try {

            $response = $this->apiClass()->make_request('lists', ['limit' => 30]);
            $lists    = $response['body']['data'] ?? [];

            if (is_array($lists) && ! empty($lists)) {
                foreach ($lists as $list) {
                    $list_array[$list['id']] = $list['title'];
                }
            }

            // Handle pagination if needed
            $next_cursor = $response['body']['next_cursor'] ?? null;

            while ($next_cursor) {

                try {

                    $next_response = $this->apiClass()->make_request('lists', ['next_cursor' => $next_cursor]);

                    $next_lists = $next_response['body']['data'] ?? [];

                    if (is_array($next_lists) && ! empty($next_lists)) {
                        foreach ($next_lists as $list) {
                            $list_array[$list['id']] = $list['title'];
                        }
                    }

                    $next_cursor = $next_response['body']['next_cursor'] ?? false;

                } catch (Exception $e) {
                    // Stop pagination on error
                    break;
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
            ->set_id('first_name')
            ->set_name(esc_html__('First Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('last_name')
            ->set_name(esc_html__('Last Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('number')
            ->set_name(esc_html__('Phone Number', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('created_at')
            ->set_name(esc_html__('Created At', 'fusewp'))
            ->set_data_type(ContactFieldEntity::DATETIME_FIELD);

        return $bucket;
    }

    /**
     * @inheritDoc
     */
    public function get_sync_action()
    {
        return new SyncAction($this);
    }

    /**
     * @return APIClass
     *
     * @throws Exception
     */
    public function apiClass()
    {
        $public_key  = fusewpVar($this->get_settings(), 'public_key');
        $private_key = fusewpVar($this->get_settings(), 'private_key');

        if (empty($public_key)) {
            throw new Exception(__('Engage.so API Public Key not found.', 'fusewp'));
        }

        if (empty($private_key)) {
            throw new Exception(__('Engage.so API Private Key not found.', 'fusewp'));
        }

        return new APIClass($public_key, $private_key);
    }
}
