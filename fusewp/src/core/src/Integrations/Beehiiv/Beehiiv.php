<?php

namespace FuseWP\Core\Integrations\Beehiiv;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Beehiiv extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'beehiiv';

        $this->title = 'Beehiiv';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/beehiiv-integration.svg';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);
    }

    /**
     * @return array
     */
    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    /**
     * @return bool
     */
    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'api_key')) && ! empty(fusewpVar($settings, 'publication_id'));
        });
    }

    /**
     * @return string
     */
    public function connection_settings()
    {
        $html = '';

        if ($this->is_connected()) {
            $html .= sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'));
        }

        $html .= '<form method="post">';
        $html .= sprintf(
            '<p><label for="fusewp-beehiiv-api_key">%s</label> <input placeholder="%s" id="fusewp-beehiiv-api_key" class="regular-text" type="password" name="fusewp-beehiiv-api_key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-beehiiv-publication_id">%s</label> <input placeholder="%s" id="fusewp-beehiiv-publication_id" class="regular-text" type="text" name="fusewp-beehiiv-publication_id" value="%s"></p>',
            esc_html__('Publication ID', 'fusewp'),
            esc_html__('Enter Publication ID', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'publication_id'))
        );
        $html .= sprintf(
            '<p>%s</p>',
            sprintf(
                __('Log in to your %sBeehiiv account%s to get your publication ID.', 'mailoptin'),
                '<a target="_blank" href="https://app.beehiiv.com/settings/workspace/api">',
                '</a>'
            )
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_beehiiv_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_beehiiv_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $publication_id = sanitize_text_field($_POST['fusewp-beehiiv-publication_id']);

                // Add 'pub_' prefix if it doesn't already have it
                if ( ! empty($publication_id) && strpos($publication_id, 'pub_') !== 0) {
                    $publication_id = 'pub_' . $publication_id;
                }

                $old_data                              = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key']        = sanitize_text_field($_POST['fusewp-beehiiv-api_key']);
                $old_data[$this->id]['publication_id'] = $publication_id;
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function get_email_list()
    {
        $lists = ['all' => __('Free Tier', 'mailoptin')];

        try {

            $response = $this->apiClass()->make_request('publications/{publicationId}/tiers');

            if (isset($response['body']->data) && is_array($response['body']->data)) {

                foreach ($response['body']->data as $tier) {

                    $lists[$tier->id] = $tier->name;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $lists;
    }

    /**
     * @param string $list_id
     *
     * @return array
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        try {

            $response = $this->apiClass()->make_request('publications/{publicationId}/custom_fields');

            if (isset($response['body']->data) && is_array($response['body']->data)) {

                foreach ($response['body']->data as $customField) {

                    switch ($customField->kind) {
                        case 'double':
                        case 'integer':
                            $data_type = ContactFieldEntity::NUMBER_FIELD;
                            break;
                        case 'boolean':
                            $data_type = ContactFieldEntity::BOOLEAN_FIELD;
                            break;
                        case 'date':
                            $data_type = ContactFieldEntity::DATE_FIELD;
                            break;
                        case 'datetime':
                            $data_type = ContactFieldEntity::DATETIME_FIELD;
                            break;
                        case 'list':
                            $data_type = ContactFieldEntity::MULTISELECT_FIELD;
                            break;
                        default:
                            $data_type = ContactFieldEntity::TEXT_FIELD;
                    }

                    $bucket[] = (new ContactFieldEntity())
                        ->set_id($customField->display)
                        ->set_name($customField->display)
                        ->set_data_type($data_type);
                }
            }
        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

    /**
     * @return SyncAction
     */
    public function get_sync_action()
    {
        return new SyncAction($this);
    }

    /**
     * @return APIClass
     *
     * @throws \Exception
     */
    public function apiClass()
    {
        $api_key        = fusewpVar($this->get_settings(), 'api_key');
        $publication_id = fusewpVar($this->get_settings(), 'publication_id');

        if (empty($api_key)) {
            throw new \Exception(__('Beehiiv API Key not found.', 'fusewp'));
        }

        if (empty($publication_id)) {
            throw new \Exception(__('Beehiiv publication ID not found.', 'fusewp'));
        }

        return new APIClass($api_key, $publication_id);
    }
}
