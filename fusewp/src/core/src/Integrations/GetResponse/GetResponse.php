<?php

namespace FuseWP\Core\Integrations\GetResponse;

use Exception;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class GetResponse extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'getresponse';

        $this->title = 'GetResponse';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/getresponse-integration.svg';

        parent::__construct();

        add_action('admin_init', [$this, 'handle_saving_api_credentials']);
        add_action('fusewp_after_admin_settings_page', [$this, 'is_max_account_js_toggle']);
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function handle_saving_api_credentials()
    {
        if (isset($_POST['fusewp_getresponse_save_settings'])) {
            check_admin_referer('fusewp_save_integration_settings');

            if (current_user_can('manage_options')) {

                $old_data                                 = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
                $old_data[$this->id]['api_key']           = sanitize_text_field($_POST['fusewp-getresponse-api-key']);
                $old_data[$this->id]['is_max']            = isset($_POST['fusewp-getresponse-is-max']) ? sanitize_text_field($_POST['fusewp-getresponse-is-max']) : 'off';
                $old_data[$this->id]['registered_domain'] = untrailingslashit(sanitize_text_field($_POST['fusewp-getresponse-registered-domain']));
                $old_data[$this->id]['country']           = sanitize_text_field($_POST['fusewp-getresponse-country']);
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
            '<p><label for="fusewp-getresponse-api-key">%s</label> <input placeholder="%s" id="fusewp-getresponse-api-key" class="regular-text" type="password" name="fusewp-getresponse-api-key" value="%s"></p>',
            esc_html__('API Key', 'fusewp'),
            esc_html__('Enter API Key', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'api_key'))
        );
        $html .= sprintf(
            '<p><label for="fusewp-getresponse-max">%s <input id="fusewp-getresponse-max" class="regular-text" type="checkbox" name="fusewp-getresponse-is-max" %s></label></p>',
            esc_html__('GetResponse MAX Account', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'is_max', 'off')) === 'on' ? 'checked' : ''
        );
        $html .= sprintf(
            '<p id="fusewp-getresponse-registered-domain-p"><label for="fusewp-getresponse-registered-domain">%s</label> <input placeholder="%s" id="fusewp-getresponse-registered-domain" class="regular-text" type="text" name="fusewp-getresponse-registered-domain" value="%s"></p>',
            esc_html__('Domain', 'fusewp'),
            esc_html__('Enter Registered Domain', 'fusewp'),
            esc_attr(fusewpVar($this->get_settings(), 'registered_domain'))
        );

        $html .= '<p id="fusewp-getresponse-country-p">';
        $html .= sprintf('<label for="fusewp-getresponse-country">%s</label>', esc_html__('Country', 'fusewp'));
        $html .= '<select name="fusewp-getresponse-country" id="fusewp-getresponse-country">';
        foreach (
            [
                null     => esc_html__('Select country', 'fusewp'),
                'poland' => 'Poland',
                'others' => 'Others'
            ] as $key => $name
        ) {
            $html .= sprintf('<option value="%s"%s>%s</option>', $key,
                selected($key, esc_attr(fusewpVar($this->get_settings(), 'country')), false), $name);
        }
        $html .= '</select>';
        $html .= '</p>';
        $html .= wp_nonce_field('fusewp_save_integration_settings');
        $html .= sprintf('<input type="submit" class="button-primary" name="fusewp_getresponse_save_settings" value="%s"></form>',
            esc_html__('Save Changes', 'fusewp'));

        return $html;
    }

    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'api_key')) && (
                    (empty(fusewpVar($settings, 'is_max')) || (fusewpVar($settings, 'is_max') === 'off'))
                    || (fusewpVar($settings, 'is_max') === 'on') && ! empty(fusewpVar($settings,
                        'registered_domain')) && ! empty(fusewpVar($settings, 'country'))
                );
        });
    }

    /**
     * @inheritDoc
     */
    public function get_email_list()
    {
        $list_array = [];
        try {
            $response  = $this->apiClass()->make_request('campaigns', ['perPage' => 1000]);
            $campaigns = $response['body'] ?? [];

            if ( ! empty($campaigns)) {
                foreach ($campaigns as $campaign) {
                    $list_array[$campaign['campaignId']] = $campaign['name'];
                }
            }
        } catch (Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list_array;
    }

    /**
     * @return APIClass
     *
     * @throws Exception
     */
    public function apiClass()
    {
        $api_key = fusewpVar($this->get_settings(), 'api_key');
        $is_max  = fusewpVar($this->get_settings(), 'is_max');

        $registered_domain = '';
        $country           = '';

        if (empty($api_key)) {
            throw new Exception(__('GetResponse API Key not found.', 'fusewp'));
        }

        if ( ! empty($is_max) && $is_max == 'on') {

            $registered_domain = fusewpVar($this->get_settings(), 'registered_domain', null, true);
            $country           = fusewpVar($this->get_settings(), 'country', null, true);

            if (empty($registered_domain)) {
                throw new Exception(__('GetResponse Registered Domain not found.', 'fusewp'));
            }

            if (empty($country)) {
                throw new Exception(__('GetResponse Country not found.', 'fusewp'));
            }
        }

        return new APIClass($api_key, $registered_domain, $country);
    }

    /**
     * @inheritDoc
     */
    public function get_tags_list()
    {
        $tag_array = [];
        try {
            $response = $this->apiClass()->make_request('tags', ['perPage' => 1000]);
            $tags     = $response['body'] ?? [];

            if ( ! empty($tags)) {
                foreach ($tags as $tag) {
                    $tag_array[$tag['tagId']] = $tag['name'];
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

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpFirstName')
            ->set_name(esc_html__('First Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpLastName')
            ->set_name(esc_html__('Last Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpIPAddress')
            ->set_name(esc_html__('IP Address', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        if (fusewp_is_premium()) {

            try {
                // https://apireference.getresponse.com/#operation/getCustomFieldList
                $response = $this->apiClass()->make_request('custom-fields', ['perPage' => 1000]);

                if (isset($response['body']) && is_array($response['body'])) {

                    foreach ($response['body'] as $customField) {
                        switch ($customField['type']) {
                            case 'date':
                                $data_type = ContactFieldEntity::DATE_FIELD;
                                break;
                            case 'datetime':
                                $data_type = ContactFieldEntity::DATETIME_FIELD;
                                break;
                            case 'multi_select':
                                $data_type = ContactFieldEntity::MULTISELECT_FIELD;
                                break;
                            case 'number':
                                $data_type = ContactFieldEntity::NUMBER_FIELD;
                                break;
                            default:
                                $data_type = ContactFieldEntity::TEXT_FIELD;
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($customField['customFieldId'])
                            ->set_name($customField['name'])
                            ->set_data_type($data_type);
                    }
                }
            } catch (Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
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

    public function is_max_account_js_toggle()
    {
        ?>
        <script type="text/javascript">
            jQuery(function ($) {
                function is_checked() {
                    return $('#fusewp-getresponse-max').is(':checked');
                }

                $('#fusewp-getresponse-registered-domain-p').toggle(is_checked());
                $('#fusewp-getresponse-country-p').toggle(is_checked());

                $('#fusewp-getresponse-max').on('change', function () {
                    $('#fusewp-getresponse-registered-domain-p').toggle(this.checked);
                    $('#fusewp-getresponse-country-p').toggle(this.checked);
                })
            });
        </script>
        <?php
    }
}
