<?php

namespace FuseWP\Core\Integrations\Flodesk;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Flodesk extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'flodesk';

        $this->title = 'Flodesk';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/flodesk-integration.png';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);

        add_filter('fusewp_settings_page', [$this, 'settings']);
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

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpLastName')
            ->set_name(esc_html__('Last Name', 'fusewp'));

        if (fusewp_is_premium()) {

            try {

                $response = $this->apiClass()->make_request('custom-fields', ['per_page' => 100]);

                if (isset($response['body']->data) && is_array($response['body']->data)) {

                    foreach ($response['body']->data as $customField) {

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($customField->key)
                            ->set_name($customField->label)
                            ->set_data_type(ContactFieldEntity::TEXT_FIELD);
                    }
                }

            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
        }

        return $bucket;
    }

    public function get_sync_action()
    {
        return new SyncAction($this);
    }

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_flodesk_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                       = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key'] = sanitize_text_field($_POST['fusewp-flodesk-api-key']);
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
            '<p><label for="fusewp-flodesk-api-key">%s</label> <input placeholder="%s" id="fusewp-flodesk-api-key" class="regular-text" type="password" name="fusewp-flodesk-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
            '<p class="regular-text">%s</p>',
            sprintf(
                __('Log in to your %sFlodesk account%s to get your api key.', 'fusewp'),
                '<a target="_blank" href="https://app.flodesk.com/account/integrations/api">',
                '</a>')
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_flodesk_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        $bucket = [];

        try {

            $response = $this->apiClass()->make_request('segments', ['per_page' => 100]);

            if (isset($response['body']->data) && is_array($response['body']->data)) {

                foreach ($response['body']->data as $segment) {
                    $bucket[$segment->id] = $segment->name;
                }
            }

            return $bucket;

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

    public function settings($args)
    {
        if ($this->is_connected()) {

            $args['flodesk_settings'] = [
                'section_title'             => esc_html__('Flodesk Settings', 'fusewp'),
                'flodesk_sync_double_optin' => [
                    'type'           => 'checkbox',
                    'value'          => 'yes',
                    'label'          => esc_html__('Sync Double Optin', 'fusewp'),
                    'checkbox_label' => esc_html__('Check to Enable', 'fusewp'),
                    'description'    => esc_html__('Double optin requires users to confirm their email address before they are added or subscribed.', 'fusewp'),
                ]
            ];

            if ( ! fusewp_is_premium()) {
                unset($args['flodesk_settings']['flodesk_sync_double_optin']);

                $content = __("Upgrade to FuseWP Premium to enable double optin when subscribing users to Flodesk during sync.", 'fusewp');

                $html = '<div class="fusewp-upsell-block">';
                $html .= sprintf('<p>%s</p>', $content);
                $html .= '<p>';
                $html .= '<a class="button" target="_blank" href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=flodesk_sync_double_optin">';
                $html .= esc_html__('Upgrade to FuseWP Premium', 'fusewp');
                $html .= '</a>';
                $html .= '</p>';
                $html .= '</div>';

                $args['flodesk_settings']['flodesk_doi_upsell'] = [
                    'type' => 'arbitrary',
                    'data' => $html,
                ];
            }
        }

        return $args;
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
            throw new \Exception(__('Flodesk API Key not found.', 'fusewp'));
        }

        return new APIClass($api_key);
    }
}
