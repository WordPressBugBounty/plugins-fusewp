<?php

namespace FuseWP\Core\Integrations\Klaviyo;

use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $klaviyoInstance;

    /**
     * @param Klaviyo $klaviyoInstance
     */
    public function __construct($klaviyoInstance)
    {
        $this->klaviyoInstance = $klaviyoInstance;
    }

    public function get_integration_id()
    {
        return $this->klaviyoInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->klaviyoInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;')
        ];

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->klaviyoInstance->title)
            ->set_integration_contact_fields($this->klaviyoInstance->get_contact_fields($list_id))
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
                    'text'
                ],
                'field_values'        => [
                    '$first_name',
                    '$last_name'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = [];

        $output['main']['location']['ip'] = fusewp_get_ip_address();

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    $mappable_data_id = $mappable_data[$index];

                    $data = $mappingUserDataEntity->get($mappable_data_id);

                    if ( ! empty($mappable_data_id)) {

                        $klaviyo_field_id = $field_value;

                        if (is_array($data)) $data = implode(', ', $data);

                        if ($klaviyo_field_id == '$first_name') {
                            $output['main']['first_name'] = $data;
                            continue;
                        }

                        if ($klaviyo_field_id == '$last_name') {
                            $output['main']['last_name'] = $data;
                            continue;
                        }

                        if (in_array(
                            $klaviyo_field_id,
                            [
                                '$address1',
                                '$address2',
                                '$city',
                                '$country',
                                '$region',
                                '$zip'
                            ])) {
                            $output['main']['location'][str_replace('$', '', $klaviyo_field_id)] = $data;
                            continue;
                        }

                        if (empty($klaviyo_field_id)) {
                            $output['extra'][$mappable_data_id] = $data;
                        } else {
                            $output['main'][str_replace('$', '', $klaviyo_field_id)] = $data;
                        }
                    }
                }
            }
        }

        return $output;
    }

    /**
     * {@inheritdoc}
     *
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $is_email_change = ! empty($old_email_address) && $email_address != $old_email_address;

            $parameters = ['main' => ['email' => $email_address]];

            $field_mapping = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $parameters['main'] = array_merge($parameters['main'], $field_mapping['main']);

            if ( ! empty($field_mapping['extra'])) {
                $parameters['extra'] = $field_mapping['extra'];
            }

            $parameters = apply_filters(
                'fusewp_klaviyo_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            if ($is_email_change) {
                $is_contact_exist = $this->is_contact_exist($old_email_address);
            } else {
                $is_contact_exist = $this->is_contact_exist($email_address);
            }

            if ($is_contact_exist) {
                $response = $this->klaviyoInstance->apiClass()->add_subscriber($list_id, $parameters, $is_contact_exist);
            } else {
                $response = $this->klaviyoInstance->apiClass()->add_subscriber($list_id, $parameters);
            }

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {

            fusewp_log_error($this->klaviyoInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $contact_id = $this->is_contact_exist($email_address);

            if ($contact_id) {

                $payload = [
                    'data' => [
                        [
                            'type' => 'profile',
                            'id'   => $contact_id
                        ]
                    ]
                ];

                $response = $this->klaviyoInstance->apiClass()->make_request("lists/$list_id/relationships/profiles/", $payload, 'delete');

                return fusewp_is_http_code_success($response['status_code']);
            }

        } catch (\Exception $e) {

            fusewp_log_error($this->klaviyoInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);
        }

        return false;
    }

    public function is_contact_exist($email)
    {
        try {

            $response = $this->klaviyoInstance->apiClass()->make_request('profiles/', ['filter' => sprintf('equals(email,"%s")', $email)]);

            return $response['body']->data[0]->id ?? false;

        } catch (\Exception $e) {
            return false;
        }
    }
}