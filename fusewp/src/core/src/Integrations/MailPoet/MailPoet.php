<?php

namespace FuseWP\Core\Integrations\MailPoet;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;
use MailPoet\API\API;

class MailPoet extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'mailpoet';

        $this->title = 'MailPoet';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/mailpoet-integration.svg';

        parent::__construct();
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function is_connected()
    {
        return class_exists('\MailPoet\API\API');
    }

    public function connection_settings()
    {
        $message = $this->is_connected() ? esc_html__('Connected because MailPoet is installed and active on this website', 'fusewp') :
            esc_html__('Not connected because MailPoet is not activated on this website', 'fusewp');

        return sprintf('<p>%s</p>', $message);
    }

    /**
     * @return array
     */
    public function get_email_list()
    {
        $lists = [];

        try {
            if (self::is_connected()) {

                $items = API::MP('v1')->getLists();

                if ( ! empty($items)) {

                    foreach ($items as $item) {

                        // remove trash segment.
                        if ( ! empty($item['deleted_at'])) continue;

                        // only segments of the type "default" can be manually subscribed to.
                        // Other segment types are automatically generated and don't /shouldn't accept subscribers.
                        if ($item['type'] != 'default') continue;

                        $lists[$item['id']] = $item['name'];
                    }
                }
            }
        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $lists;
    }

    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        $default = [
            'email'      => esc_html__('Email', 'fusewp'),
            'first_name' => esc_html__('First Name', 'fusewp'),
            'last_name'  => esc_html__('Last Name', 'fusewp'),
        ];

        foreach ($default as $key => $value) {
            $bucket[] = (new ContactFieldEntity())
                ->set_id($key)
                ->set_name($value)
                ->set_data_type(ContactFieldEntity::TEXT_FIELD);
        }

        if (self::is_connected() && fusewp_is_premium()) {

            try {

                $custom_fields = API::MP('v1')->getSubscriberFields();

                if (is_array($custom_fields) && ! empty($custom_fields)) {

                    foreach ($custom_fields as $custom_field) {

                        if (in_array($custom_field['id'], ['email', 'first_name', 'last_name'])) continue;

                        $datatype = ContactFieldEntity::TEXT_FIELD;

                        if ('date' == $custom_field['type']) {
                            $datatype = ContactFieldEntity::DATE_FIELD;
                        }

                        if ('checkbox' == $custom_field['type']) {
                            $datatype = ContactFieldEntity::BOOLEAN_FIELD;
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id($custom_field['id'])
                            ->set_name($custom_field['name'])
                            ->set_data_type($datatype);
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
}
