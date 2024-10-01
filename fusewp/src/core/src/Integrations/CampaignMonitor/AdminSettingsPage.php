<?php

namespace FuseWP\Core\Integrations\CampaignMonitor;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    /** @var CampaignMonitor */
    protected $campaignmonitorInstance;

    /**
     * @param CampaignMonitor $campaignmonitorInstance
     */
    public function __construct($campaignmonitorInstance)
    {
        parent::__construct($campaignmonitorInstance);

        $this->campaignmonitorInstance = $campaignmonitorInstance;

        add_action('fusewp_admin_notices', [$this, 'output_invalid_client_id_error']);
        add_action('fusewp_after_save_oauth_credentials', [$this, 'save_default_client_id']);
        add_action('admin_init', [$this, 'handle_saving_client']);
    }

    private function get_saved_client_id()
    {
        return fusewpVar($this->campaignmonitorInstance->get_settings(), 'client_id');
    }

    private function client_id_select_dropdown()
    {
        $saved_client_id = $this->get_saved_client_id();

        $clients = ['' => '&mdash;&mdash;&mdash;'];

        try {

            $clients = $clients + $this->campaignmonitorInstance->apiClass()->getClients();

        } catch (\Exception $e) {
            fusewp_log_error($this->campaignmonitorInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        $output = '<select name="fusewp-campaign-monitor-client">';

        foreach ($clients as $key => $name) {
            $output .= sprintf('<option value="%s"%s>%s</option>', $key, selected($key, $saved_client_id, false), $name);
        }

        $output .= '</select>';

        return $output;
    }

    public function connection_settings()
    {
        $label = sprintf(esc_html__('Connect to %s', 'fusewp'), $this->integrationInstance->title);

        $account_name = fusewpVar($this->integrationInstance->get_settings(), 'accountname');

        if ($this->integrationInstance->is_connected()) {
            $label = sprintf(esc_html__('Reconnect to %s', 'fusewp'), $this->integrationInstance->title);
        }
        $html = sprintf('<p>%s %s</p>', $this->integrationInstance->title, esc_html__('requires external authorization. You will need to connect our application with your account to proceed.', 'fusewp'));

        if ($this->integrationInstance->is_connected()) {
            if ( ! empty($account_name)) {
                $html .= sprintf('<p><strong>%s: %s</strong></p>', esc_html__('Connected to', 'fusewp'), $account_name);
            } else {
                $html .= sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'));
            }
        }

        $html .= sprintf('<p><a href="%s" class="button">%s</a></p>', $this->integrationInstance->get_connect_url(), $label);

        if ($this->integrationInstance->is_connected()) {
            $html .= sprintf('<p><a class="fusewp-confirm-delete" href="%s">%s</a></p>', $this->integrationInstance->get_disconnect_url(), esc_html__('Disconnect', 'fusewp'));
            $html .= sprintf('<form method="post"><p>%s</p>', $this->client_id_select_dropdown());
            $html .= wp_nonce_field('fusewp_save_integration_settings');
            $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_campaign_monitor_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));
        }

        return $html;
    }

    public function output_invalid_client_id_error()
    {
        if (fusewp_is_admin_page() && $this->campaignmonitorInstance->is_connected() && empty($this->get_saved_client_id())) {

            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . sprintf(
                    esc_html__('FuseWP Error: Client ID is %smissing in %s settings%s', 'fusewp'),
                    '<a href="' . FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE . '">',
                    $this->integrationInstance->title,
                    '</a>'
                ) . '</strong>';
            echo '</p></div>';
        }
    }

    public function save_default_client_id($data)
    {
        try {
            $clients = $this->campaignmonitorInstance->apiClass()->getClients();
        } catch (\Exception $e) {
            $clients = [];
        }

        $old_data                                                  = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
        $old_data[$this->campaignmonitorInstance->id]['client_id'] = array_key_first($clients);
        update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);
    }

    public function handle_saving_client()
    {
        if (isset($_POST['fusewp_campaign_monitor_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                                                  = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->campaignmonitorInstance->id]['client_id'] = sanitize_text_field($_POST['fusewp-campaign-monitor-client']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }
}