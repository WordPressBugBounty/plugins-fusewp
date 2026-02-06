<?php

namespace FuseWP\Core\Integrations\Sender;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity as CFE;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $senderInstance;

    /**
     * @param Sender $senderInstance
     */
    public function __construct($senderInstance)
    {
        $this->senderInstance = $senderInstance;
    }

    /**
     * @return mixed
     */
    public function get_integration_id()
    {
        return $this->senderInstance->id;
    }

    /**
     * @param $index
     *
     * @return mixed
     */
    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Group', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->senderInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
        ];

        if ( ! fusewp_is_premium()) {
            $fields[] = (new Custom($prefix('sender_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map Sender.net custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=sender_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                });
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->senderInstance->title)
            ->set_integration_contact_fields($this->senderInstance->get_contact_fields($list_id))
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
                    'text',
                ],
                'field_values'        => [
                    'firstname',
                    'lastname'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = ['fields' => []];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $field_type = fusewpVar($mappable_data_types, $index);
                        $data       = $mappingUserDataEntity->get($mappable_data[$index]);

                        if (in_array($field_value, ['firstname', 'lastname', 'email', 'phone'])) {
                            $output[$field_value] = $data;
                            continue;
                        }

                        if ($field_type == CFE::NUMBER_FIELD) {
                            $data = absint($data);
                        }

                        if ($field_type == CFE::DATE_FIELD) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == CFE::DATETIME_FIELD) {
                            $data = gmdate('c', fusewp_strtotime_utc($data));
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        $output['fields'][$field_value] = $data;
                    }
                }
            }
        }

        return $output;
    }

    /**
     * @param $list_id
     * @param $email_address
     * @param $mappingUserDataEntity
     * @param $custom_fields
     * @param $tags
     * @param $old_email_address
     *
     * @return bool
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {
            // does not support email change

            $parameters = apply_filters(
                'fusewp_sender_subscription_parameters',
                array_filter(
                    array_merge(
                        ['email' => $email_address, 'groups' => [$list_id]],
                        $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity)),
                    'fusewp_is_valid_data'
                ),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            $contact = $this->find_subscriber_by_email($email_address);

            $endpoint = $contact ? "subscribers/{$contact->id}" : 'subscribers';
            $method   = $contact ? 'patch' : 'post';

            // Make API request with automatic retry on phone errors
            try {

                $response = $this->senderInstance->apiClass()->make_request($endpoint, $parameters, $method);

            } catch (\Exception $e) {
                if (strstr($e->getMessage(), 'errors') && strstr($e->getMessage(), 'phone')) {
                    unset($parameters['phone']);
                    $response = $this->senderInstance->apiClass()->make_request($endpoint, $parameters, $method);
                } else {
                    throw $e;
                }
            }

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            fusewp_log_error($this->senderInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    public function find_subscriber_by_email($email)
    {
        if ( ! empty($email)) {

            try {

                $response = $this->senderInstance->apiClass()->make_request("subscribers/{$email}");

                return $response['body']->data;

            } catch (\Exception $e) {
            }
        }

        return false;
    }

    /**
     * @param $list_id
     * @param $email_address
     *
     * @return bool
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {
            $parameters = ['subscribers' => [$email_address]];

            $response = $this->senderInstance->apiClass()->make_request("subscribers/groups/$list_id", $parameters, 'delete');

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            fusewp_log_error($this->senderInstance->id, __METHOD__ . ':' . $e->getMessage());

            return false;
        }
    }
}
