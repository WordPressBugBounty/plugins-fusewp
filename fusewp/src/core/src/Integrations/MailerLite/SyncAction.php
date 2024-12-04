<?php

namespace FuseWP\Core\Integrations\MailerLite;

use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $mailerliteInstance;

    /**
     * @param MailerLite $mailerliteInstance
     */
    public function __construct($mailerliteInstance)
    {
        $this->mailerliteInstance = $mailerliteInstance;
    }

    public function get_integration_id()
    {
        return $this->mailerliteInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        return [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Group', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->mailerliteInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;')
        ];
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->mailerliteInstance->title)
            ->set_integration_contact_fields($this->mailerliteInstance->get_contact_fields($list_id))
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
                    'name',
                    'last_name'
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

                        $field_type = fusewpVar($mappable_data_types, $index);

                        if ($field_type == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::NUMBER_FIELD) {
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

    /**
     * {@inheritdoc}
     *
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $is_email_change = ! empty($old_email_address) && $email_address != $old_email_address;

            $parameters = [
                'email'      => $email_address,
                'groups'     => [$list_id],
                'ip_address' => fusewp_get_ip_address()
            ];

            $parameters['fields'] = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $parameters = apply_filters(
                'fusewp_mailerlite_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            $update_flag = false;

            if ($is_email_change) {

                $subscriber_id = $this->get_contact_id($old_email_address);

                if ( ! empty($subscriber_id)) {
                    $update_flag = true;
                    $response    = $this->mailerliteInstance->apiClass()->make_request("subscribers/$subscriber_id", $parameters, 'put');
                }
            }

            if ( ! $update_flag) {
                $response = $this->mailerliteInstance->apiClass()->make_request('subscribers', $parameters, 'post');
            }

            if (fusewp_is_http_code_success($response['status'])) {
                return true;
            }

            throw new \Exception(is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {
            fusewp_log_error($this->mailerliteInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $subscriber_id = $this->get_contact_id($email_address);

            if ( ! empty($subscriber_id)) {

                $response = $this->mailerliteInstance->apiClass()->make_request(
                    "subscribers/$subscriber_id/groups/$list_id",
                    [],
                    'delete'
                );

                return fusewp_is_http_code_success($response['status']);
            }

        } catch (\Exception $e) {
        }

        return false;
    }

    protected function get_contact_id($email_address)
    {
        try {

            $response = $this->mailerliteInstance->apiClass()->make_request("subscribers/" . urlencode($email_address));

            return $response['body']['data']['id'] ?? false;

        } catch (\Exception $e) {

            return false;
        }
    }
}