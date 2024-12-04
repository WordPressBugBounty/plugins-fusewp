<?php

namespace FuseWP\Core\Integrations\Sendy;

use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $sendyInstance;

    /**
     * @param $sendyInstance
     */
    public function __construct($sendyInstance)
    {
        $this->sendyInstance = $sendyInstance;
    }

    public function get_integration_id()
    {
        return $this->sendyInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        return [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->sendyInstance->get_email_list())
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
            ->set_integration_name($this->sendyInstance->title)
            ->set_integration_contact_fields($this->sendyInstance->get_contact_fields($list_id))
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
                    'fusewpFirstName',
                    'fusewpLastName'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = [];

        $first_name = '';
        $last_name  = '';

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $sendy_field_id = $field_value;

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($sendy_field_id == 'fusewpFirstName') {
                            $first_name = $data;
                            continue;
                        }

                        if ($sendy_field_id == 'fusewpLastName') {
                            $last_name = $data;
                            continue;
                        }

                        if ($sendy_field_id == 'fusewpCountry') {
                            $output['country'] = $data;
                            continue;
                        }

                        if ($sendy_field_id == 'fusewpIPAddress') {
                            if (filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                if ($data !== '127.0.0.1' && $data !== '::1') {
                                    $output['ipaddress'] = $data;
                                }
                            }
                            continue;
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        $output[$sendy_field_id] = $data;
                    }
                }
            }
        }

        $output['name'] = self::get_full_name($first_name, $last_name);

        return $output;
    }

    /**
     * {@inheritdoc}
     *  Does not support user email change.
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $parameters = [
                'email'   => $email_address,
                'list'    => $list_id,
                'boolean' => 'true'
            ];

            $transformed_data = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $parameters = array_merge($parameters, $transformed_data);

            $parameters = apply_filters(
                'fusewp_sendy_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            $response = $this->sendyInstance->apiClass()->post('subscribe', $parameters);

            if (apply_filters('fusewp_sendy_subscription_delete_subscriber_on_update', false, $parameters)) {

                if ($response['body'] == 'Already subscribed.') {

                    $this->delete_subscriber($email_address, $list_id);

                    $response = $this->sendyInstance->apiClass()->post('subscribe', $parameters);
                }
            }

            if (in_array($response['body'], [true, '1', 1, 'Already subscribed.'], true)) {
                return true;
            }

            throw new \Exception($response['body']);

        } catch (\Exception $e) {
            fusewp_log_error($this->sendyInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $response = $this->sendyInstance->apiClass()->post(
                "unsubscribe",
                ['email' => $email_address, 'list' => $list_id, 'boolean' => 'true']
            );

            return in_array($response['body'], [true, '1', 1], true);

        } catch (\Exception $e) {
            return false;
        }
    }

    protected function delete_subscriber($email, $list_id)
    {
        try {

            $this->sendyInstance->apiClass()->make_request(
                "api/subscribers/delete.php",
                ['list_id' => $list_id, 'email' => $email],
                'post'
            );

        } catch (\Exception $e) {
        }
    }
}