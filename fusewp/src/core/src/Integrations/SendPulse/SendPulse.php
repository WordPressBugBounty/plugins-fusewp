<?php

namespace FuseWP\Core\Integrations\SendPulse;

use Exception;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class SendPulse extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'sendpulse';

        $this->title = 'SendPulse';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/sendpulse-integration.svg';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);
        add_filter('fusewp_settings_page', [$this, 'settings']);
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_sendpulse_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                             = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['client_id']     = sanitize_text_field($_POST['fusewp-sendpulse-client-id']);
                $old_data[$this->id]['client_secret'] = sanitize_text_field($_POST['fusewp-sendpulse-client-secret']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                // Clear stored token when credentials change
                delete_option(APIClass::TOKEN_OPTION_KEY);

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
            '<p><label for="fusewp-sendpulse-client-id">%s</label> <input placeholder="%s" id="fusewp-sendpulse-client-id" class="regular-text" type="text" name="fusewp-sendpulse-client-id" value="%s"></p>',
            esc_html__('Client ID', 'fusewp'),
            esc_html__('Enter Client ID', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'client_id'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-sendpulse-client-secret">%s</label> <input placeholder="%s" id="fusewp-sendpulse-client-secret" class="regular-text" type="password" name="fusewp-sendpulse-client-secret" value="%s"></p>',
            esc_html__('Client Secret', 'fusewp'),
            esc_html__('Enter Client Secret', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'client_secret'))
        );
        $html .= sprintf(
            '<p class="regular-text">%s</p>',
            sprintf(
                __('Get your API credentials from %sSettings → API%s in your SendPulse account.', 'fusewp'),
                '<a target="_blank" href="https://login.sendpulse.com/settings">',
                '</a>'
            )
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_sendpulse_save_settings" value="%s"></form>',
            esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'client_id')) &&
                   ! empty(fusewpVar($settings, 'client_secret'));
        });
    }

    /**
     * https://sendpulse.com/integrations/api/bulk-email#lists-list
     */
    public function get_email_list()
    {
        $list_array = [];
        try {
            $response = $this->apiClass()->make_request('addressbooks', ['limit' => 100]);
            $lists    = $response['body'] ?? [];

            if (is_array($lists) && ! empty($lists)) {
                foreach ($lists as $list) {
                    // Only show active lists (status 0)
                    if (isset($list['status']) && $list['status'] === 0) { // Funny that zero means active.
                        $list_array[$list['id']] = $list['name'];
                    }
                }
            }
        } catch (Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list_array;
    }

    /**
     * https://sendpulse.com/integrations/api/bulk-email#get-a-list-of-tags
     */
    public function get_tags_list()
    {
        $tag_array = [];
        try {
            $response = $this->apiClass()->make_request('tags');
            $tags     = $response['body']['tags'] ?? [];

            if (is_array($tags) && ! empty($tags)) {
                foreach ($tags as $tag) {
                    $tag_array[$tag['id']] = $tag['name'];
                }
            }
        } catch (Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $tag_array;
    }

    /**
     * @inheritDoc
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        $custom_fields = [
            'fusewpFirstName' => esc_html__('First Name', 'fusewp'),
            'fusewpLastName'  => esc_html__('Last Name', 'fusewp'),
            'phone'           => esc_html__('Phone', 'fusewp'),
        ];

        foreach ($custom_fields as $field_id => $field) {
            // skip custom fields if lite
            if ( ! fusewp_is_premium() && ! in_array($field_id, ['fusewpFirstName', 'fusewpLastName'])) continue;

            $bucket[] = (new ContactFieldEntity())
                ->set_id($field_id)
                ->set_name($field)
                ->set_data_type(ContactFieldEntity::TEXT_FIELD);
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

    /**
     * Add SendPulse settings to the FuseWP settings page
     *
     * @param array $args
     *
     * @return array
     */
    public function settings($args)
    {
        if ($this->is_connected()) {

            $args['sendpulse_settings'] = [
                'section_title'               => esc_html__('SendPulse Settings', 'fusewp'),
                'sendpulse_sync_double_optin' => [
                    'type'           => 'checkbox',
                    'value'          => 'yes',
                    'label'          => esc_html__('Sync Double Optin', 'fusewp'),
                    'checkbox_label' => esc_html__('Check to Enable', 'fusewp'),
                    'description'    => esc_html__('Double optin requires users to confirm their email address before they are added or subscribed.', 'fusewp'),
                ],
                'sendpulse_sender_email'      => [
                    'type'        => 'text',
                    'label'       => esc_html__('Sender Email (for Double Optin)', 'fusewp'),
                    'description' => sprintf(
                        esc_html__('Required for double optin. Must be an activated %ssender in your SendPulse account%s.', 'fusewp'),
                        '<a target="_blank" href="https://login.sendpulse.com/emailservice/senders/">', '</a>'
                    ),
                ],
                'sendpulse_message_lang'      => [
                    'type'        => 'select',
                    'label'       => esc_html__('Double-Optin Email Language', 'fusewp'),
                    'options'     => [
                        'en' => esc_html__('English', 'fusewp'),
                        'ru' => esc_html__('Russian', 'fusewp'),
                        'ua' => esc_html__('Ukrainian', 'fusewp'),
                        'tr' => esc_html__('Turkish', 'fusewp'),
                        'es' => esc_html__('Spanish', 'fusewp'),
                        'pt' => esc_html__('Portuguese', 'fusewp')
                    ],
                    'description' => esc_html__('Required when double optin is enabled.', 'fusewp'),
                ],
                'sendpulse_template_id'       => [
                    'type'        => 'text',
                    'label'       => esc_html__('Confirmation Template ID (optional)', 'fusewp'),
                    'description' => sprintf(
                        esc_html__('Optional. Confirmation email template ID from %sSendPulse Service Settings%s. Leave empty to use default.', 'fusewp'),
                        '<a target="_blank" href="https://login.sendpulse.com/emailservice/confirmation-letters/">', '</a>'
                    ),
                ]
            ];

            if ( ! fusewp_is_premium()) {
                unset($args['sendpulse_settings']['sendpulse_sync_double_optin']);
                unset($args['sendpulse_settings']['sendpulse_sender_email']);
                unset($args['sendpulse_settings']['sendpulse_message_lang']);
                unset($args['sendpulse_settings']['sendpulse_template_id']);

                $content = __("Upgrade to FuseWP Premium to enable double optin when subscribing users to SendPulse during sync.", 'fusewp');

                $html = '<div class="fusewp-upsell-block">';
                $html .= sprintf('<p>%s</p>', $content);
                $html .= '<p>';
                $html .= '<a class="button" target="_blank" href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=sendpulse_sync_double_optin">';
                $html .= esc_html__('Upgrade to FuseWP Premium', 'fusewp');
                $html .= '</a>';
                $html .= '</p>';
                $html .= '</div>';

                $args['sendpulse_settings']['sendpulse_doi_upsell'] = [
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
     * @throws Exception
     */
    public function apiClass()
    {
        $client_id     = fusewpVar($this->get_settings(), 'client_id');
        $client_secret = fusewpVar($this->get_settings(), 'client_secret');

        if (empty($client_id)) {
            throw new Exception(__('SendPulse Client ID not found.', 'fusewp'));
        }

        if (empty($client_secret)) {
            throw new Exception(__('SendPulse Client Secret not found.', 'fusewp'));
        }

        return new APIClass($client_id, $client_secret);
    }
}
