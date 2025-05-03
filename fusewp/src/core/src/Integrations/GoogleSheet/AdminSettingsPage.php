<?php

namespace FuseWP\Core\Integrations\GoogleSheet;

use Authifly\Provider\Google;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    /** @var GoogleSheet */
    protected $googleSheetInstance;

    public function __construct($googleSheetInstance)
    {
        parent::__construct($googleSheetInstance);

        $this->googleSheetInstance = $googleSheetInstance;

        add_action('admin_init', [$this, 'authorize_integration']);
        add_action('admin_init', [$this, 'handle_saving_credentials']);
    }

    public function authorize_integration()
    {
        if ( ! current_user_can('manage_options')) return;

        if (fusewpVarGET('fusewpauth') != $this->googleSheetInstance->id) return;

        $settings = $this->googleSheetInstance->get_settings();

        $callback_url = $this->googleSheetInstance->callback_url();
        if (defined('W3GUY_LOCAL')) {
            $callback_url = str_replace(home_url(), 'https://w3guy.dev', $callback_url);
        }

        $config = [
            'callback' => $callback_url,
            'keys'     => [
                'id'     => fusewpVar($settings, 'client_id'),
                'secret' => fusewpVar($settings, 'client_secret')
            ],
            'scope'    => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive'
        ];

        $instance = new Google($config, null, new OAuthCredentialStorage());

        try {

            $instance->authenticate();

            $access_token = $instance->getAccessToken();

            if ( ! empty($access_token)) {
                $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
                $old_data    = get_option($option_name, []);

                $old_data[$this->googleSheetInstance->id]['access_token']  = fusewpVar($access_token, 'access_token');
                $old_data[$this->googleSheetInstance->id]['refresh_token'] = fusewpVar($access_token, 'refresh_token');
                $old_data[$this->googleSheetInstance->id]['expires_at']    = fusewpVar($access_token, 'expires_at');
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->googleSheetInstance->id, __METHOD__ . ':' . $e->getMessage());
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

        $doc_url = 'https://fusewp.com/article/connect-wordpress-with-google-sheets/';
        $guide   = '<ol>';
        $guide   .= '<li>' . sprintf(esc_html__('Create a Google API project in %1$sGoogle console%2$s.', 'fusewp'), '<a href="https://console.cloud.google.com/apis" target="_blank" rel="noopener">', '</a>') . '</li>';
        $guide   .= '<li>' . sprintf(esc_html__('Copy the %1$sproject keys%2$s and include them below.', 'fusewp'), '<a href="' . esc_url($doc_url) . '" target="_blank" rel="noopener">', '</a>') . '</li>';
        $guide   .= '<li>' . sprintf(esc_html__('Use %1$s as the Authorized Redirect URI.', 'fusewp'), '<strong>' . $this->googleSheetInstance->callback_url() . '</strong>');
        $guide   .= '<li>' . sprintf(esc_html__('Click the Authorize button to complete the connection. %sLearn more%s', 'fusewp'), '<a href="' . $doc_url . '" target="_blank">', '</a>') . '</li>';
        $guide   .= '</ol>';

        $html .= sprintf('<p>%s</p>', $guide);

        // Connected - Clear cache or Disconnect
        if ($this->integrationInstance->is_connected()) {

            $label = sprintf(esc_html__('Reconnect to %s', 'fusewp'), $this->googleSheetInstance->title);

            $html = sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'));

            $html .= sprintf('<p><a href="%s" class="button">%s</a></p>', $this->googleSheetInstance->callback_url(), $label);

            $html .= sprintf('<p><a href="%s">%s</a></p>', $this->integrationInstance->get_clear_cache_url(), esc_html__('Clear cache', 'fusewp'));

            $html .= sprintf('<p><a class="fusewp-confirm-delete button" href="%s">%s</a></p>', $this->integrationInstance->get_disconnect_url(), esc_html__('Disconnect', 'fusewp'));

            return $html;
        }

        // Credentials saved but not connected - Connect
        if ($this->googleSheetInstance->is_credentials_saved()) {
            $html .= sprintf('<p><a href="%s" class="button">%s</a></p>', $this->googleSheetInstance->callback_url(), 'AUTHORIZE YOUR ACCOUNT');

            $html .= sprintf('<p><a href="%s">%s</a></p>', $this->integrationInstance->get_clear_cache_url(), esc_html__('Clear cache', 'fusewp'));

            $html .= sprintf('<p><a class="fusewp-confirm-delete button" href="%s">%s</a></p>', $this->integrationInstance->get_disconnect_url(), esc_html__('Disconnect', 'fusewp'));

            return $html;
        }

        $html .= '<form method="post">';
        $html .= sprintf(
            '<p><label for="fusewp-google_sheet-client_id">%s</label> <input placeholder="%s" id="fusewp-google_sheet-client_id" class="regular-text" type="text" name="fusewp-google_sheet-client_id" value="%s"></p>',
            esc_html__('Client ID', 'fusewp'),
            esc_html__('Enter Client ID', 'fusewp'),
            esc_attr(fusewpVar($this->googleSheetInstance->get_settings(), 'client_id'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-google_sheet-client_secret">%s</label> <input placeholder="%s" id="fusewp-google_sheet-client_secret" class="regular-text" type="password" name="fusewp-google_sheet-client_secret" value="%s"></p>',
            esc_html__('Client Secret', 'fusewp'),
            esc_html__('Enter Client Secret', 'fusewp'),
            esc_attr(fusewpVar($this->googleSheetInstance->get_settings(), 'client_secret'))
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_google_sheet_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function handle_saving_credentials()
    {
        if (isset($_POST['fusewp_google_sheet_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                                                  = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->googleSheetInstance->id]['client_id']     = sanitize_text_field($_POST['fusewp-google_sheet-client_id']);
                $old_data[$this->googleSheetInstance->id]['client_secret'] = sanitize_text_field($_POST['fusewp-google_sheet-client_secret']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }
}
