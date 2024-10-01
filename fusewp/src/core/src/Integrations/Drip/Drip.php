<?php

namespace FuseWP\Core\Integrations\Drip;

use Exception;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Drip extends AbstractIntegration
{
    public $accountId = '';

    public function __construct()
    {
        $this->id = 'drip';

        $this->title = 'Drip';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/drip-integration.svg';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'api_token')) && ! empty(fusewpVar($settings, 'account_id'));
        });
    }

    public function connection_settings()
    {
        $html = '';

        if ($this->is_connected()) {
            $html .= sprintf('<p><strong>%s</strong></p>', esc_html__('Connection Successful', 'fusewp'));
        }

        $html .= '<form method="post">';
        $html .= sprintf(
            '<p><label for="fusewp-drip-api-token">%s</label> <input placeholder="%s" id="fusewp-drip-api-token" class="regular-text" type="password" name="fusewp-drip-api-token" value="%s"></p>',
            esc_html__('API Token', 'fusewp'),
            esc_html__('Enter API Token', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_token'))
        );
        $html .= sprintf(
            '<p class="regular-text">%s</p>',
            sprintf(
                __('Log in to your %sDrip account%s to get your api token.', 'fusewp'),
                '<a target="_blank" href="https://www.getdrip.com/user/edit">',
                '</a>')
        );
        $html .= sprintf(
            '<p><label for="fusewp-drip-account-id">%s</label> <input placeholder="%s" id="fusewp-drip-account-id" class="regular-text" type="text" name="fusewp-drip-account-id" value="%s"></p>',
            esc_html__('Account ID', 'fusewp'),
            esc_html__('Enter Account ID', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'account_id'))
        );
        $html .= sprintf(
            '<p class="regular-text">%s</p>',
            __('Get your "account ID" from "General Info" settings.', 'fusewp'),
        );
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_drip_save_settings" value="%s"></form>', esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    /**
     * https://developer.drip.com/?shell#tags
     * @return array
     */
    public function get_email_list()
    {
        $list_array = [];
        try {
            $response = $this->apiClass()->make_request('tags');
            $tags     = $response['body']['tags'] ?? [];

            if ( ! empty($tags)) {
                foreach ($tags as $tag) {
                    $list_array[$tag] = $tag;
                }
            }
        } catch (Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list_array;
    }

    /**
     * @param $list_id
     *
     * @return array|ContactFieldEntity[]
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        $bucket[] = (new ContactFieldEntity())
            ->set_id('first_name')
            ->set_name(esc_html__('First Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('last_name')
            ->set_name(esc_html__('Last Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        if (fusewp_is_premium()) {
            $fields = [
                'address1'           => 'Address 1',
                'address2'           => 'Address 2',
                'city'               => 'City',
                'state'              => 'State',
                'zip'                => 'Zip',
                'country'            => 'Country',
                'phone'              => 'Phone',
                'sms_number'         => 'SMS Number',
                'sms_consent'        => 'SMS Consent',
                'eu_consent'         => 'EU Consent',
                'eu_consent_message' => 'EU Consent Message',
                'user_id'            => 'User ID',
                'time_zone'          => 'Time Zone',
                'lifetime_value'     => 'Lifetime Value',
                'ip_address'         => 'IP Address',
                'base_lead_score'    => 'Base Lead Score',
                'prospect'           => 'Prospect',
            ];

            foreach ($fields as $k => $v) {

                $data_type = ContactFieldEntity::TEXT_FIELD;

                if (in_array($k, ['sms_consent', 'prospect'])) {
                    $data_type = ContactFieldEntity::BOOLEAN_FIELD;
                }

                $bucket[] = (new ContactFieldEntity())
                    ->set_id($k)
                    ->set_name($v)
                    ->set_data_type($data_type);
            }

            try {

                // https://developer.drip.com/?shell#custom-fields
                $response = $this->apiClass()->make_request('custom_field_identifiers');

                if (isset($response['body']['custom_field_identifiers']) && is_array($response['body']['custom_field_identifiers'])) {

                    foreach ($response['body']['custom_field_identifiers'] as $customField) {

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id('drip_custom_' . $customField)
                            ->set_name($customField)
                            ->set_data_type(ContactFieldEntity::TEXT_FIELD);
                    }
                }

            } catch (Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
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

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_drip_save_settings'])) {

            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                          = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_token']  = sanitize_text_field($_POST['fusewp-drip-api-token']);
                $old_data[$this->id]['account_id'] = sanitize_text_field($_POST['fusewp-drip-account-id']);
                update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);

                wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
                exit;
            }
        }
    }

    /**
     * @return APIClass
     *
     * @throws Exception
     */
    public function apiClass()
    {
        $this->accountId = fusewpVar($this->get_settings(), 'account_id');
        $api_token       = fusewpVar($this->get_settings(), 'api_token');

        if (empty($api_token)) {
            throw new Exception(__('Drip API Token not found.', 'fusewp'));
        }

        if (empty($this->accountId)) {
            throw new Exception(__('Drip Account ID not found.', 'fusewp'));
        }

        return new APIClass($this->accountId, $api_token);
    }
}
