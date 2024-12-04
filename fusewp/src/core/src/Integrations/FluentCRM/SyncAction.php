<?php

namespace FuseWP\Core\Integrations\FluentCRM;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $fluentcrmInstance;

    /**
     * @param FluentCRM $fluentcrmInstance
     */
    public function __construct($fluentcrmInstance)
    {
        $this->fluentcrmInstance = $fluentcrmInstance;
    }

    public function get_integration_id()
    {
        return $this->fluentcrmInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->fluentcrmInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_is_multiple()
                ->set_options($this->fluentcrmInstance->get_tags())
                ->set_description(esc_html__('Select the tags to assign to contacts.', 'fusewp')),
            (new Custom($prefix('fluentcrm_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact and map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=fluentcrm_sync_destination_upsell" target="_blank">',
                            '</a>'
                        ) . '</p>';
                }),
        ];

        if ( ! fusewp_is_premium()) {
            unset($fields[1]);
        } else {
            unset($fields[2]);
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->fluentcrmInstance->title)
            ->set_integration_contact_fields($this->fluentcrmInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    public function get_list_fields_default_data()
    {
        return [
            'custom_fields' => [
                'mappable_data'       => [
                    'first_name',
                    'last_name'
                ],
                'mappable_data_types' => [
                    'text',
                    'text'
                ],
                'field_values'        => [
                    'first_name',
                    'last_name'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = ['custom_values' => []];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $fluentcrm_field_id   = $field_value;
                        $fluentcrm_field_type = fusewpVar($mappable_data_types, $index);

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($fluentcrm_field_type == ContactFieldEntity::DATE_FIELD) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($fluentcrm_field_type == ContactFieldEntity::DATETIME_FIELD) {
                            $data = gmdate('Y-m-d H:i:s', fusewp_strtotime_utc($data));
                        }

                        if ($fluentcrm_field_type == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = array_filter((array)$data);
                        }

                        if (is_array($data) && $fluentcrm_field_type != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        if (strstr($fluentcrm_field_id, 'fcrm_cf_') !== false) {
                            $output['custom_values'][str_replace('fcrm_cf_', '', $fluentcrm_field_id)] = $data;
                        } else {
                            $output[$fluentcrm_field_id] = $data;
                        }
                    }
                }
            }
        }

        return $output;
    }

    private function is_double_optin()
    {
        return fusewp_get_settings('fluentcrm_sync_double_optin') == 'yes';
    }

    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        if ( ! function_exists('FluentCrmApi')) return false;

        $func_args = $this->get_sync_payload_json_args(func_get_args());

        $parameters = [
            'email'  => $email_address,
            'status' => $this->is_double_optin() ? 'pending' : 'subscribed',
            'lists'  => [$list_id],
            'tags'   => $tags,
        ];

        $transformed_data = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity);

        $parameters = apply_filters(
            'fusewp_fluentcrm_subscription_parameters',
            array_merge($parameters, $transformed_data),
            $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
        );

        try {

            $contactApi = FluentCrmApi('contacts');

            $response = $contactApi->createOrUpdate($parameters);

            if ($response->status == 'pending') {
                $response->sendDoubleOptinEmail();
            }

            return true;

        } catch (\Exception $e) {
            fusewp_log_error($this->fluentcrmInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            FluentCrmApi('contacts')->getContact($email_address)->detachLists([$list_id]);

            return true;

        } catch (\Exception $e) {

            fusewp_log_error($this->fluentcrmInstance->id, __METHOD__ . ':' . $e->getMessage());

            return false;
        }
    }
}
