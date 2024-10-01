<?php

namespace FuseWP\Core\Integrations\Mailchimp;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $mailchimpInstance;

    /**
     * @param Mailchimp $mailchimpInstance
     */
    public function __construct($mailchimpInstance)
    {
        parent::__construct($mailchimpInstance);

        $this->mailchimpInstance = $mailchimpInstance;

        add_filter('fusewp_before_save_oauth_credentials', [$this, 'save_oauth_metadata'], 10, 2);

        add_filter('fusewp_settings_page', [$this, 'settings']);
    }

    public function save_oauth_metadata($settings, $integration_id)
    {
        if ($integration_id == $this->mailchimpInstance->id) {
            $access_token = $settings[$integration_id]['access_token'] ?? false;
            if ( ! empty($access_token)) {
                try {
                    $response = $this->mailchimpInstance->apiClass($access_token)->getOauthMetaData();
                    if ($response) {
                        $settings[$integration_id]['dc']          = $response->dc;
                        $settings[$integration_id]['accountname'] = $response->accountname;
                    }
                } catch (\Exception $e) {
                    fusewp_log_error($this->mailchimpInstance->id, __METHOD__ . ':' . $e->getMessage());
                }
            }
        }

        return $settings;
    }

    public function settings($args)
    {
        if ($this->integrationInstance->is_connected()) {

            $args['mailchimp_settings'] = [
                'section_title'               => esc_html__('Mailchimp Settings', 'fusewp'),
                'mailchimp_sync_double_optin' => [
                    'type'           => 'checkbox',
                    'value'          => 'yes',
                    'label'          => esc_html__('Sync Double Optin', 'fusewp'),
                    'checkbox_label' => esc_html__('Check to Enable', 'fusewp'),
                    'description'    => esc_html__('Double optin requires users to confirm their email address before they are added or subscribed.', 'fusewp'),
                ]
            ];

            if ( ! fusewp_is_premium()) {
                unset($args['mailchimp_settings']['mailchimp_sync_double_optin']);

                $content = __("Upgrade to FuseWP Premium to enable double optin when subscribing users to Mailchimp during sync.", 'fusewp');

                $html = '<div class="fusewp-upsell-block">';
                $html .= sprintf('<p>%s</p>', $content);
                $html .= '<p>';
                $html .= '<a class="button" target="_blank" href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=mailchimp_sync_double_optin">';
                $html .= esc_html__('Upgrade to FuseWP Premium', 'fusewp');
                $html .= '</a>';
                $html .= '</p>';
                $html .= '</div>';

                $args['mailchimp_settings']['mailchimp_doi_upsell'] = [
                    'type' => 'arbitrary',
                    'data' => $html,
                ];
            }
        }

        return $args;
    }
}