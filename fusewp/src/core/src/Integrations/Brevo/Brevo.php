<?php

namespace FuseWP\Core\Integrations\Brevo;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Brevo extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'brevo';

        $this->title = 'Brevo';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/brevo-integration.svg';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);

        add_filter('fusewp_settings_page', [$this, 'brevo_settings']);
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

        // not restricting this to premium because first/last names can varies based on Brevo account language and not separately passed to Brevo api

        try {

            $response = $this->apiClass()->make_request('contacts/attributes');

            if (isset($response['body']->attributes) && is_array($response['body']->attributes)) {

                foreach ($response['body']->attributes as $customField) {

                    $data_type = ContactFieldEntity::TEXT_FIELD;

                    if (isset($customField->type)) {

                        switch ($customField->type) {
                            case 'date':
                                $data_type = ContactFieldEntity::DATE_FIELD;
                                break;
                            case 'float':
                                $data_type = ContactFieldEntity::NUMBER_FIELD;
                                break;
                            case 'boolean':
                                $data_type = ContactFieldEntity::BOOLEAN_FIELD;
                                break;
                        }
                    }

                    $bucket[] = (new ContactFieldEntity())
                        ->set_id($customField->name)
                        ->set_name($customField->name)
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
        if (isset($_POST['fusewp_brevo_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                       = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key'] = sanitize_text_field($_POST['fusewp-brevo-api-key']);
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
            '<p><label for="fusewp-brevo-api-key">%s</label> <input placeholder="%s" id="fusewp-brevo-api-key" class="regular-text" type="password" name="fusewp-brevo-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
        '<p class="regular-text">%s</p>',
        sprintf(
            __('Log in to your %sBrevo account%s to get your API v3 key.', 'fusewp'),
            '<a target="_blank" href="https://app.brevo.com/settings/keys/api">',
            '</a>')
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_brevo_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function get_email_list()
    {
        $list_array = [];

        try {

            $offset = 0;
            $limit  = 50;
            $loop   = true;

            while ($loop === true) {

                // note any value > 50 results in {"code":"out_of_range","message":"Limit exceeds max value"}
                $response = $this->apiClass()->make_request('contacts/lists', ['offset' => $offset, 'limit' => $limit]);

                if (isset($response['body']->lists) && is_array($response['body']->lists)) {

                    foreach ($response['body']->lists as $list) {
                        $list_array[$list->id] = $list->name;
                    }

                    if (count($response['body']->lists) < $limit) {
                        $loop = false;
                    }

                    $offset += $limit;
                } else {
                    $loop = false;
                    fusewp_log_error($this->id, __METHOD__ . ':' . is_string($response['body']) ? $response : wp_json_encode($response['body']));
                }
            }

            return $list_array;

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list_array;
    }

    public function get_double_optin_template()
    {
        $double_optin_templates = ['' => '&mdash;&mdash;&mdash;'];

        try {

            $response = $this->apiClass()->make_request('smtp/templates', [
                'templateStatus' => 'true',
                'limit'          => 1000
            ]);

            if (isset($response['body']->templates) && ! empty($response['body']->templates)) {

                foreach ($response['body']->templates as $template) {
                    $double_optin_templates[$template->id] = $template->name;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $double_optin_templates;
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
            throw new \Exception(__('Brevo API Key not found.', 'fusewp'));
        }

        return new APIClass($api_key);
    }

    public function brevo_settings($args)
    {
        if ($this->is_connected()) {

            $args['brevo_settings'] = [
                'section_title'                      => esc_html__('Brevo Settings', 'fusewp'),
                'brevo_double_optin_template'        => [
                    'type'        => 'select',
                    'options'     => $this->get_double_optin_template(),
                    'label'       => esc_html__('Select Double Optin Template', 'fusewp'),
                    'description' => esc_html__('Enable double-optin by selecting a double-optin template.', 'fusewp'),
                ],
                'brevo_double_optin_redirection_url' => [
                    'type'        => 'text',
                    'options'     => $this->get_double_optin_template(),
                    'label'       => esc_html__('Redirection URL', 'fusewp'),
                    'description' => esc_html__('The URL that user will be redirected to after clicking on the double optin confirmation link.', 'fusewp'),
                ]
            ];

            if ( ! fusewp_is_premium()) {

                unset($args['brevo_settings']['brevo_double_optin_template']);
                unset($args['brevo_settings']['brevo_double_optin_redirection_url']);

                $content = __("Upgrade to FuseWP Premium to enable double optin when subscribing users to Brevo during sync.", 'fusewp');

                $html = '<div class="fusewp-upsell-block">';
                $html .= sprintf('<p>%s</p>', $content);
                $html .= '<p>';
                $html .= '<a class="button" target="_blank" href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=brevo_sync_double_optin">';
                $html .= esc_html__('Upgrade to FuseWP Premium', 'fusewp');
                $html .= '</a>';
                $html .= '</p>';
                $html .= '</div>';

                $args['brevo_settings']['brevo_doi_upsell'] = [
                    'type' => 'arbitrary',
                    'data' => $html,
                ];
            }
        }

        return $args;
    }
}
