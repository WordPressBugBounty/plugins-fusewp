<?php

namespace FuseWP\Core\Integrations\Salesforce;

use Authifly\Provider\Salesforce as AuthiflySalesforce;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $salesforceInstance;

    /**
     * @param Salesforce $salesforceInstance
     */
    public function __construct($salesforceInstance)
    {
        parent::__construct($salesforceInstance);

        $this->salesforceInstance = $salesforceInstance;

        add_action('admin_init', [$this, 'authorize_integration']);
        add_action('admin_init', [$this, 'handle_saving_credentials']);
    }

    public function authorize_integration()
    {
        if ( ! current_user_can('manage_options')) {
            return;
        }

        if (fusewpVarGET('fusewpauth') != $this->salesforceInstance->id) {
            return;
        }

        $settings = $this->salesforceInstance->get_settings();

        $config = [
            'callback' => $this->salesforceInstance->callback_url(),
            'keys'     => [
                'id'     => fusewpVar($settings, 'consumer_key'),
                'secret' => fusewpVar($settings, 'consumer_secret')
            ]
        ];

        $instance = new AuthiflySalesforce($config, null, new OAuthCredentialStorage());

        try {
            $instance->authenticate();

            $access_token = $instance->getAccessToken();

            if ( ! empty($access_token)) {
                $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
                $old_data    = get_option($option_name, []);

                $old_data[$this->salesforceInstance->id]['instance_url']  = fusewpVar($access_token, 'instance_url');
                $old_data[$this->salesforceInstance->id]['access_token']  = fusewpVar($access_token, 'access_token');
                $old_data[$this->salesforceInstance->id]['refresh_token'] = fusewpVar($access_token, 'refresh_token');
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->salesforceInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        $instance->disconnect();

        wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
        exit;
    }

    /**
     * @return string
     */
    public function connection_settings()
    {
        $html = '';

        $guide = '<div style="font-weight: 500;line-height: 1.5;margin: 20px 0;">' . sprintf(esc_html__('An application must be created with Salesforce to get your API Key and App Secret. %sLearn more%s', 'fusewp'), '<a target="_blank" href="https://fusewp.com/article/connect-wordpress-with-salesforce/">', '</a>') . '</div>';
        $guide .= '<ul>';
        $guide .= '<li>' . esc_html__('In Salesforce, go to Setup -> App -> App Manager and click on "New Connected App".', 'fusewp') . '</li>';
        $guide .= '<li>' . esc_html__('Enter Application Name(eg. My App), email address then check "Enable OAuth Settings" checkbox.', 'fusewp') . '</li>';
        $guide .= '<li>' . sprintf(__('Enter %s as the Callback URL.', 'fusewp'), '<code>' . $this->salesforceInstance->callback_url() . '</code>') . '</li>';
        $guide .= '<li>' . sprintf(__('Select %s and %s as OAuth Scopes then Save to create the application.', 'fusewp'), '<code>Manage user data via APIs (api)</code>', '<code>Perform requests at any time (refresh_token, offline_access)</code>') . '</li>';
        $guide .= '<li>' . esc_html__('Copy the Consumer Key and Secret of the app and save them here.', 'fusewp') . '</li>';
        $guide .= '</ul>';

        $html .= sprintf('<p>%s</p>', $guide);

        // Connected - Disconnect
        if ($this->integrationInstance->is_connected()) {

            $label = sprintf(esc_html__('Reconnect to %s', 'fusewp'), $this->salesforceInstance->title);

            $html = sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'));

            $html .= sprintf('<p><a href="%s" class="button">%s</a></p>', $this->salesforceInstance->callback_url(), $label);

            $html .= sprintf('<p><a class="fusewp-confirm-delete button" href="%s">%s</a></p>', $this->integrationInstance->get_disconnect_url(), esc_html__('Disconnect', 'fusewp'));

            if ($this->integrationInstance->has_support(AbstractIntegration::CACHE_CLEARING_SUPPORT)) {
                $html .= sprintf('<p><a href="%s">%s</a></p>', $this->integrationInstance->get_clear_cache_url(), esc_html__('Clear cache', 'fusewp'));
            }

            return $html;
        }

        // Credentials saved but not connected - Connect
        if ($this->salesforceInstance->is_credentials_saved()) {
            $html .= sprintf('<p><a href="%s" class="button">%s</a></p>', $this->salesforceInstance->callback_url(), esc_html__('AUTHORIZE YOUR ACCOUNT', 'fusewp'));

            $html .= sprintf('<p><a class="fusewp-confirm-delete button" href="%s">%s</a></p>', $this->integrationInstance->get_disconnect_url(), esc_html__('Disconnect', 'fusewp'));

            return $html;
        }

        $html .= '<form method="post">';
        $html .= sprintf(
            '<p><label for="fusewp-salesforce-consumer_key">%s</label> <input placeholder="%s" id="fusewp-salesforce-consumer_key" class="regular-text" type="text" name="fusewp-salesforce-consumer_key" value="%s"></p>',
            esc_html__('Consumer Key', 'fusewp'),
            esc_html__('Enter Consumer Key', 'fusewp'),
            esc_attr(fusewpVar($this->salesforceInstance->get_settings(), 'consumer_key'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-salesforce-consumer_secret">%s</label> <input placeholder="%s" id="fusewp-salesforce-consumer_secret" class="regular-text" type="password" name="fusewp-salesforce-consumer_secret" value="%s"></p>',
            esc_html__('Consumer Secret', 'fusewp'),
            esc_html__('Enter Consumer Secret', 'fusewp'),
            esc_attr(fusewpVar($this->salesforceInstance->get_settings(), 'consumer_secret'))
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_salesforce_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function handle_saving_credentials()
    {
        if (isset($_POST['fusewp_salesforce_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                                                   = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME,
                    []);
                $old_data[$this->salesforceInstance->id]['consumer_key']    = sanitize_text_field($_POST['fusewp-salesforce-consumer_key']);
                $old_data[$this->salesforceInstance->id]['consumer_secret'] = sanitize_text_field($_POST['fusewp-salesforce-consumer_secret']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }
}
