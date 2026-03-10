<?php

namespace FuseWP\Core\Integrations\Birdsend;

use Authifly\Provider\Birdsend as AuthiflyBirdsend;
use Authifly\Storage\OAuthCredentialStorage;
use Exception;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Birdsend extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'birdsend';

        $this->title = 'BirdSend';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/birdsend-integration.png';

        $this->adminSettingsPageInstance = new AdminSettingsPage($this);

        parent::__construct();
    }

    /**
     * @return array
     */
    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    /**
     * @return mixed
     */
    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'access_token'));
        });
    }

    /**
     * @return mixed
     */
    public function connection_settings()
    {
        return $this->adminSettingsPageInstance->connection_settings();
    }

    /**
     * https://developer.birdsend.co/api-reference.html#list-tags
     * @return array
     */
    public function get_email_list()
    {
        $list_array = [];
        try {
            $response = $this->apiClass()->apiRequest('tags');
            $tags     = $response->data ?? [];

            if ( ! empty($tags)) {
                foreach ($tags as $tag) {
                    $list_array[$tag->tag_id . ':' . $tag->name] = $tag->name;
                }
            }
        } catch (Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list_array;
    }

    /**
     * https://developer.birdsend.co/api-reference.html#list-custom-fields
     * @param $list_id
     *
     * @return ContactFieldEntity[]
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

        $bucket[] = (new ContactFieldEntity())
            ->set_id('ipaddress')
            ->set_name(esc_html__('IP Address', 'fusewp'))
            ->set_data_type(ContactFieldEntity::TEXT_FIELD);

        if (fusewp_is_premium()) {
            try {
                $page = 1;
                while (true) {
                    $response = $this->apiClass()->apiRequest('fields', 'GET', ['page' => $page]);
                    if (empty($response->data)) {
                        break;
                    }

                    foreach ($response->data as $customField) {
                        if (in_array($customField->key, ['first_name', 'last_name'])) {
                            continue;
                        }
                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($customField->key)
                            ->set_name($customField->label)
                            ->set_data_type(ContactFieldEntity::TEXT_FIELD);
                    }

                    if (
                        ! isset($response->meta->current_page) ||
                        ! isset($response->meta->last_page) ||
                        $response->meta->current_page >= $response->meta->last_page
                    ) {
                        break;
                    }
                    $page++;
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

    /**
     * @param $config_access_token
     *
     *
     * @return AuthiflyBirdsend
     * @throws Exception
     */
    public function apiClass($config_access_token = '')
    {
        $settings = $this->get_settings();

        $access_token = fusewpVar($settings, 'access_token', '');

        if ( ! empty($config_access_token)) {
            $access_token = $config_access_token;
        }

        if (empty($access_token)) {
            throw new Exception(__('Birdsend access token not found.', 'fusewp'));
        }

        $expires_at    = (int) fusewpVar($settings, 'expires_at', '');
        $refresh_token = fusewpVar($settings, 'refresh_token', '');

        $config = [
            'callback' => FUSEWP_OAUTH_URL,
            'keys'     => ['key' => '6475', 'secret' => '__'],
        ];

        $instance = new AuthiflyBirdsend($config, null, new OAuthCredentialStorage([
            'birdsend.access_token'   => $access_token,
            'birdsend.refresh_token'  => $refresh_token,
            'birdsend.expires_at'     => $expires_at,
        ]));

        if ($instance->hasAccessTokenExpired()) {

            $result = $this->oauth_token_refresh($refresh_token);

            if ($result) {

                $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
                $old_data    = get_option($option_name, []);

                $old_data[$this->id]['access_token']   = $result['data']['access_token'];
                $old_data[$this->id]['refresh_token']   = $result['data']['refresh_token'] ?? $refresh_token;
                $old_data[$this->id]['expires_at']     = $result['data']['expires_at'];

                update_option($option_name, $old_data);

                $instance = new AuthiflyBirdsend($config, null, new OAuthCredentialStorage([
                    'birdsend.access_token'   => $result['data']['access_token'],
                    'birdsend.refresh_token'  => $result['data']['refresh_token'] ?? $refresh_token,
                    'birdsend.expires_at'     => $result['data']['expires_at'],
                ]));
            }
        }

        return $instance;
    }
}
