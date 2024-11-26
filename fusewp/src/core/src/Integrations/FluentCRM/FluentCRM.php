<?php

namespace FuseWP\Core\Integrations\FluentCRM;

use FluentCrm\App\Models\Subscriber as SubscriberModel;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class FluentCRM extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'fluentcrm';

        $this->title = 'FluentCRM';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/fluentcrm-integration.svg';

        new AdminSettingsPage($this);

        parent::__construct();
    }

    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    public function is_connected()
    {
        return function_exists('FluentCrmApi');
    }

    public function connection_settings()
    {
        $message = $this->is_connected() ? esc_html__('Connected because FluentCRM is installed and active on this website', 'fusewp') :
            esc_html__('Not connected because FluentCRM is not activated on this website', 'fusewp');

        return sprintf('<p>%s</p>', $message);
    }

    public function get_email_list()
    {
        $list_array = [];

        try {

            if (self::is_connected()) {

                $response = FluentCrmApi('lists')->all();

                foreach ($response as $list) {
                    $list_array[$list->id] = $list->title;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $list_array;
    }

    public function get_tags()
    {
        $tags = [];

        try {

            if (self::is_connected()) {

                $all_Tags = FluentCrmApi('tags')->all();

                if (is_object($all_Tags) && ! is_wp_error($all_Tags)) {
                    foreach ($all_Tags as $tag) {
                        if (isset($tag->id)) {
                            $tags[$tag->id] = $tag->title;
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $tags;
    }

    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        if (class_exists('\FluentCrm\App\Models\Subscriber')) {

            $default           = SubscriberModel::mappables();
            $default['avatar'] = esc_html__('Contact Photo URL', 'fusewp');

            foreach ($default as $key => $value) {

                $data_type = ContactFieldEntity::TEXT_FIELD;

                if ($key == 'date_of_birth') $data_type = ContactFieldEntity::DATE_FIELD;

                $bucket[] = (new ContactFieldEntity())
                    ->set_id($key)
                    ->set_name($value)
                    ->set_data_type($data_type);
            }

            if (self::is_connected() && fusewp_is_premium()) {

                $custom_fields = fluentcrm_get_option('contact_custom_fields', []);

                if (is_array($custom_fields) && ! empty($custom_fields)) {

                    foreach ($custom_fields as $custom_field) {

                        switch ($custom_field['type']) {
                            case 'select-multi':
                            case 'checkbox':
                                $datatype = ContactFieldEntity::MULTISELECT_FIELD;
                                break;
                            case 'date_time':
                                $datatype = ContactFieldEntity::DATETIME_FIELD;
                                break;
                            case 'date':
                                $datatype = ContactFieldEntity::DATE_FIELD;
                                break;
                            default:
                                $datatype = ContactFieldEntity::TEXT_FIELD;
                        }

                        $bucket[] = (new ContactFieldEntity())
                            ->set_id('fcrm_cf_' . $custom_field['slug'])
                            ->set_name($custom_field['label'])
                            ->set_data_type($datatype);
                    }
                }
            }
        }

        return $bucket;
    }

    public function get_sync_action()
    {
        return new SyncAction($this);
    }
}
