<?php

namespace FuseWP\Core\Integrations\Engage;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $engageInstance;

    /**
     * @param Engage $engageInstance
     */
    public function __construct($engageInstance)
    {
        $this->engageInstance = $engageInstance;
    }

    public function get_integration_id()
    {
        return $this->engageInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->engageInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Custom($prefix('engage_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=engage_sync_destination_upsell" target="_blank">',
                            '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
            unset($fields[1]);
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->engageInstance->title)
            ->set_integration_contact_fields($this->engageInstance->get_contact_fields($list_id))
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

    /**
     * @param string $email
     *
     * @return string
     */
    protected function getUserUidByEmail($email)
    {
        try {

            $response = $this->engageInstance->apiClass()->make_request('users', ['email' => $email]);

            if ( ! empty($response['body']['data'][0]['uid'])) {
                return $response['body']['data'][0]['uid'];
            }

        } catch (\Exception $e) {
        }

        return false;
    }

    protected function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = [];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $mappable_data_id = $mappable_data[$index];

                        $data       = $mappingUserDataEntity->get($mappable_data_id);
                        $field_type = fusewpVar($mappable_data_types, $index);

                        if ($field_type == ContactFieldEntity::DATE_FIELD || $field_type == ContactFieldEntity::DATETIME_FIELD) {
                            $data = gmdate('c', fusewp_strtotime_utc($data));
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        // if Engage "number" (aka phone number) field is not an actual phone number data, bail
                        if ($field_value == 'number' && preg_match('/^[0-9]{7,15}$/', $data) !== 1) continue;

                        if (empty($field_value)) {
                            if (fusewp_is_valid_data($data)) {
                                $output['meta'][$mappable_data_id] = $data;
                            }
                        } else {
                            $output[$field_value] = $data;
                        }
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

            $parameters = [
                'email' => $email_address,
                'lists' => [$list_id]
            ];

            $transformed_data = array_filter($this->transform_custom_field_data($custom_fields, $mappingUserDataEntity), 'fusewp_is_valid_data');

            $payload = array_filter(array_merge($parameters, $transformed_data), 'fusewp_is_valid_data');

            $parameters = apply_filters('fusewp_engage_subscription_parameters', $payload, $this);

            try {

                $response = $this->engageInstance->apiClass()->post("lists/$list_id/subscribers", $parameters);

                if ( ! empty($response['body']['uid'])) {

                    try {
                        // ensure attributes are updated because it doesn't if user already exist
                        $this->engageInstance->apiClass()->put(
                            "users/" . $response['body']['uid'],
                            $parameters
                        );

                    } catch (\Exception $e) {
                    }

                    return true;
                }

            } catch (\Exception $e) {
            }

            return false;

        } catch (\Exception $e) {

            fusewp_log_error($this->engageInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $user_uid = $this->getUserUidByEmail($email_address);

            if ( ! $user_uid) return false;

            $response = $this->engageInstance->apiClass()->put("lists/$list_id/subscribers/{$user_uid}", ['subscribed' => false]);

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            fusewp_log_error($this->engageInstance->id, __METHOD__ . ':' . $e->getMessage());

            return false;
        }
    }
}
