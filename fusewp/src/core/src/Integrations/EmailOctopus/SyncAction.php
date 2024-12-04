<?php

namespace FuseWP\Core\Integrations\EmailOctopus;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Admin\Fields\Text;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $emailOctopusInstance;

    /**
     * @param EmailOctopus $emailOctopusInstance
     */
    public function __construct(EmailOctopus $emailOctopusInstance)
    {
        $this->emailOctopusInstance = $emailOctopusInstance;
    }

    public function get_integration_id()
    {
        return $this->emailOctopusInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->emailOctopusInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Text($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_placeholder(esc_html__('tag1, tag2', 'fusewp'))
                ->set_description(esc_html__('Enter a comma-separated list of tags to assign to contacts.', 'fusewp')),
            (new Custom($prefix('emailoctopus_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact and map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=emailoctopus_sync_destination_upsell" target="_blank">', '</a>'
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
            ->set_integration_name($this->emailOctopusInstance->title)
            ->set_integration_contact_fields($this->emailOctopusInstance->get_contact_fields($list_id))
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
                    'FirstName',
                    'LastName'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = [];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::NUMBER_FIELD && ! empty($data)) {
                            $data = absint($data);
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        $output[$field_value] = $data;
                    }
                }
            }
        }

        return $output;
    }

    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        $main_email = ! empty($old_email_address) ? $old_email_address : $email_address;

        $parameters = [
            'email_address' => $email_address,
            'fields'        => array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            ),
            'tags'          => array_map('trim', explode(',', $tags)),
        ];

        try {
            if ($this->member_exist($list_id, $main_email)) {
                // https://emailoctopus.com/api-documentation/lists/update-contact
                $parameters['tags'] = array_fill_keys($parameters['tags'], true);
                $response           = $this->emailOctopusInstance->apiClass()->make_request(sprintf('lists/%s/contacts/%s', $list_id, md5(strtolower($main_email))), $parameters, 'put');

            } else {
                // https://emailoctopus.com/api-documentation/lists/create-contact
                $response = $this->emailOctopusInstance->apiClass()->post(sprintf('lists/%s/contacts', $list_id), $parameters);
            }

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {

            fusewp_log_error($this->emailOctopusInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    public function unsubscribe_user($list_id, $email_address)
    {
        try {
            // https://emailoctopus.com/api-documentation/lists/update-contact
            $response = $this->emailOctopusInstance->apiClass()->make_request(
                sprintf('lists/%s/contacts/%s', $list_id, md5(strtolower($email_address))),
                ['status' => 'UNSUBSCRIBED'],
                'put'
            );

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            return false;
        }
    }

    public function member_exist($list_id, $email)
    {
        try {
            // https://emailoctopus.com/api-documentation/lists/get-contact
            $response = $this->emailOctopusInstance->apiClass()->make_request(sprintf('lists/%s/contacts/%s', $list_id, md5(strtolower($email))));

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            return false;
        }
    }
}
