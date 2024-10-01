<?php

namespace FuseWP\Core\Integrations\Mailchimp;

use Authifly\Provider\Mailchimp as AuthiflyMailchimp;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Mailchimp extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'mailchimp';

        $this->title = 'Mailchimp';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/mailchimp-integration.png';

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

            return ! empty(fusewpVar($settings, 'access_token')) && ! empty(fusewpVar($settings, 'dc'));
        });
    }

    /**
     * {@inheritDoc}
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        try {

            $fields = $this->apiClass()->apiRequest(
                sprintf('/lists/%s/merge-fields', $list_id),
                'GET',
                ['count' => 1000, 'fields' => 'merge_fields.tag,merge_fields.name,merge_fields.type,merge_fields.required']
            );

            if (is_array($fields->merge_fields) && ! empty($fields->merge_fields)) {

                foreach ($fields->merge_fields as $field) {

                    // skip custom fields if lite
                    if ( ! fusewp_is_premium() && ! in_array($field->tag, ['FNAME', 'LNAME'])) continue;

                    switch ($field->type) {
                        case 'date':
                            $data_type = ContactFieldEntity::DATE_FIELD;
                            break;
                        case 'number':
                            $data_type = ContactFieldEntity::NUMBER_FIELD;
                            break;
                        default:
                            $data_type = ContactFieldEntity::TEXT_FIELD;
                    }

                    if ($field->type == 'address') {

                        $address_fields = [
                            'addr1' => 'Street Address',
                            'addr2' => 'Address Line 2',
                            'city'  => 'City',
                            'state' => 'State/Province/Region',
                            'zip'   => 'Postal/Zip Code',
                        ];

                        foreach ($address_fields as $address_key => $address_label) {

                            $bucket[] = (new ContactFieldEntity())
                                ->set_id($field->tag . '|' . $address_key)
                                ->set_name(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $field->name) . ' - ' . $address_label)
                                ->set_data_type($data_type)
                                ->set_is_required($field->required);
                        }

                    } else {

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($field->tag)
                            ->set_name(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $field->name))
                            ->set_data_type($data_type)
                            ->set_is_required($field->required);
                    }
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

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
        $bucket = [];

        try {

            $list = $this->apiClass()->apiRequest('/lists', 'GET', ['count' => 1000, 'fields' => 'lists.id,lists.name']);

            if (is_array($list->lists) && ! empty($list->lists)) {
                foreach ($list->lists as $list) {
                    $bucket[$list->id] = $list->name;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

    /**
     * @param $config_access_token
     *
     * @return AuthiflyMailchimp
     * @throws \Exception
     */
    public function apiClass($config_access_token = '')
    {
        $access_token = fusewpVar($this->get_settings(), 'access_token');

        if ( ! empty($config_access_token)) {
            $access_token = $config_access_token;
        }

        if (empty($access_token)) {
            throw new \Exception(__('Mailchimp access token not found.', 'fusewp'));
        }

        $config = [
            // secret key and callback not needed but authifly requires they have a value hence the FUSEWP_OAUTH_URL constant and "__"
            'callback'     => FUSEWP_OAUTH_URL,
            'keys'         => ['key' => '_', 'secret' => '__'],
            'access_token' => $access_token,
            'dc'           => fusewpVar($this->get_settings(), 'dc')
        ];

        return new AuthiflyMailchimp($config, null, new OAuthCredentialStorage());
    }
}