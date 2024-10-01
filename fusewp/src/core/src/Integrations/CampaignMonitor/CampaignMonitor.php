<?php

namespace FuseWP\Core\Integrations\CampaignMonitor;

use Authifly\Provider\CampaignMonitor as AuthiflyCampaignMonitor;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class CampaignMonitor extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'campaignmonitor';

        $this->title = 'Campaign Monitor';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/campaignmonitor-integration.svg';

        $this->adminSettingsPageInstance = new AdminSettingsPage($this);

        parent::__construct();
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'access_token'));
        });
    }

    /**
     * {@inheritDoc}
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpFirstName')
            ->set_name(esc_html__('First Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        $bucket[] = (new ContactFieldEntity())
            ->set_id('fusewpLastName')
            ->set_name(esc_html__('Last Name', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);


        if (fusewp_is_premium()) {

            try {

                $response = $this->apiClass()->getListCustomFields($list_id);

                if (is_array($response) && ! empty($response)) {

                    foreach ($response as $customField) {

                        $fieldKey = str_replace(['[', ']'], '', $customField->Key);

                        switch ($customField->DataType) {
                            case 'Date':
                                $data_type = ContactFieldEntity::DATE_FIELD;
                                break;
                            case 'MultiSelectMany':
                                $data_type = ContactFieldEntity::MULTISELECT_FIELD;
                                break;
                            default:
                                $data_type = ContactFieldEntity::TEXT_FIELD;
                                break;
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($fieldKey)
                            ->set_name($customField->FieldName)
                            ->set_data_type($data_type);
                    }
                }

            } catch (\Exception $e) {
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

    public function connection_settings()
    {
        return $this->adminSettingsPageInstance->connection_settings();
    }

    public function get_email_list()
    {
        try {

            $client_id = fusewpVar($this->get_settings(), 'client_id', '');

            $response = $this->apiClass()->getEmailList($client_id);

            $lists_array = array();
            if (is_array($response) && ! empty($response)) {
                foreach ($response as $list) {
                    $lists_array[$list->ListID] = $list->Name;
                }
            }

            return $lists_array;

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param $config_access_token
     *
     * @return AuthiflyCampaignMonitor
     * @throws \Exception
     */
    public function apiClass($config_access_token = '')
    {
        $settings = $this->get_settings();

        $access_token = fusewpVar($settings, 'access_token', '');

        if ( ! empty($config_access_token)) {
            $access_token = $config_access_token;
        }

        if (empty($access_token)) {
            throw new \Exception(__('CampaignMonitor access token not found.', 'fusewp'));
        }

        $expires_at    = (int)fusewpVar($settings, 'expires_at', '');
        $refresh_token = fusewpVar($settings, 'refresh_token', '');

        $config = [
            // secret key and callback not needed but authifly requires they have a value hence the FUSEWP_OAUTH_URL constant and "__"
            'callback'     => FUSEWP_OAUTH_URL,
            'keys'         => ['key' => '626f785d-a5a5-4a06-b4a8-9cd088a51394', 'secret' => '__']
        ];

        $instance = new AuthiflyCampaignMonitor($config, null, new OAuthCredentialStorage([
            'campaignmonitor.access_token'  => $access_token,
            'campaignmonitor.refresh_token' => $refresh_token,
            'campaignmonitor.expires_at'    => $expires_at,
        ]));

        if ($instance->hasAccessTokenExpired()) {

            // only requires grant_type and refresh_token parameters unlike hubspot that
            // in addition require client secret (and client ID) so no need for remote refresh.
            $instance->refreshAccessToken();

            $expires_at = $this->oauth_expires_at_transform($instance->getStorage()->get('campaignmonitor.expires_at'));

            $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
            $old_data    = get_option($option_name, []);

            $old_data['campaignmonitor']['access_token']  = $instance->getStorage()->get('campaignmonitor.access_token');
            $old_data['campaignmonitor']['refresh_token'] = $instance->getStorage()->get('campaignmonitor.refresh_token');
            $old_data['campaignmonitor']['expires_at']    = $expires_at;

            update_option($option_name, $old_data);

            $instance = new AuthiflyCampaignMonitor($config, null,
                new OAuthCredentialStorage([
                    'campaignmonitor.access_token'  => $instance->getStorage()->get('campaignmonitor.access_token'),
                    'campaignmonitor.refresh_token' => $instance->getStorage()->get('campaignmonitor.refresh_token'),
                    'campaignmonitor.expires_at'    => $expires_at
                ])
            );
        }

        return $instance;
    }
}