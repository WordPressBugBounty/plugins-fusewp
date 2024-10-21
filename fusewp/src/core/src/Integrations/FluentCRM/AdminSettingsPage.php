<?php

namespace FuseWP\Core\Integrations\FluentCRM;

class AdminSettingsPage
{
    protected $fluentcrmInstance;

    /**
     * @param FluentCRM $fluentcrmInstance
     */
    public function __construct($fluentcrmInstance)
    {
        $this->fluentcrmInstance = $fluentcrmInstance;

        add_filter('fusewp_settings_page', [$this, 'settings']);
    }

    public function settings($args)
    {
        if ($this->fluentcrmInstance->is_connected()) {

            $args['fluentcrm_settings'] = [
                'section_title'               => esc_html__('FluentCRM Settings', 'fusewp'),
                'fluentcrm_sync_double_optin' => [
                    'type'           => 'checkbox',
                    'value'          => 'yes',
                    'label'          => esc_html__('Sync Double Optin', 'fusewp'),
                    'checkbox_label' => esc_html__('Check to Enable', 'fusewp'),
                    'description'    => esc_html__('Double optin requires users to confirm their email address before they are added or subscribed.', 'fusewp'),
                ]
            ];

            if ( ! fusewp_is_premium()) {

                unset($args['fluentcrm_settings']['fluentcrm_sync_double_optin']);

                $content = __("Upgrade to FuseWP Premium to enable double optin when subscribing users to FluentCRM during sync.", 'fusewp');

                $html = '<div class="fusewp-upsell-block">';
                $html .= sprintf('<p>%s</p>', $content);
                $html .= '<p>';
                $html .= '<a class="button" target="_blank" href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=fluentcrm_sync_double_optin">';
                $html .= esc_html__('Upgrade to FuseWP Premium', 'fusewp');
                $html .= '</a>';
                $html .= '</p>';
                $html .= '</div>';

                $args['fluentcrm_settings']['fluentcrm_doi_upsell'] = [
                    'type' => 'arbitrary',
                    'data' => $html,
                ];
            }
        }

        return $args;
    }
}
