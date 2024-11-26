<?php

namespace FuseWP\Core\Integrations;

abstract class AbstractOauthAdminSettingsPage
{
    protected $integrationInstance;

    /**
     * @param IntegrationInterface $integrationInstance
     */
    public function __construct($integrationInstance)
    {
        $this->integrationInstance = $integrationInstance;

        add_action('fusewp_admin_notices', [$this, 'output_connection_error']);
    }

    public function output_connection_error()
    {
        if (fusewp_is_admin_page() && ! empty($_GET['oauth-provider']) && ! empty($_GET['oauth-error']) && $this->integrationInstance->id == $_GET['oauth-provider']) {

            echo '<div class="notice notice-error is-dismissible"><p>';
            echo '<strong>' . sprintf(esc_html__('%s Error:', 'fusewp'), $this->integrationInstance->title) . '</strong> ' . esc_html($_GET['oauth-error']);
            echo '</p></div>';
        }
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
                $html .= sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'), $account_name);
            }
        }

        $html .= sprintf('<p><a href="%s" class="button">%s</a></p>', $this->integrationInstance->get_connect_url(), $label);

        if ($this->integrationInstance->has_support(AbstractIntegration::CACHE_CLEARING_SUPPORT)) {
            $html .= sprintf('<p><a href="%s">%s</a></p>', $this->integrationInstance->get_clear_cache_url(), esc_html__('Clear cache', 'fusewp'));
        }

        if ($this->integrationInstance->is_connected()) {
            $html .= sprintf('<p><a class="fusewp-confirm-delete" href="%s">%s</a></p>', $this->integrationInstance->get_disconnect_url(), esc_html__('Disconnect', 'fusewp'));
        }

        return $html;
    }
}