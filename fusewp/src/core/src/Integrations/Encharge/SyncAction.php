<?php

namespace Fusewp\Core\Integrations\Encharge;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $enchargeInstance;

    /**
     * @param Encharge $enchargeInstance
     */
    public function __construct(Encharge $enchargeInstance)
    {
        $this->enchargeInstance = $enchargeInstance;
    }

    /**
     * @return mixed
     */
    public function get_integration_id()
    {
        return $this->enchargeInstance->id;
    }

    /**
     * @param $index
     *
     * @return array
     */
    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Tag', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->enchargeInstance->get_email_list())
                ->set_description(
                    sprintf(
                        esc_html__("Select the tag to assign to contact. Can't find the appropriate tag, %sclick here%s to add one inside Encharge", 'fusewp'),
                        '<a target="_blank" href="https://app.encharge.io/settings/tags?tags-folder-item=allTags">', '</a>'
                    )
                )
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Custom($prefix('encharge_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to to map custom fields to contacts.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=encharge_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
            unset($fields[1]);
        }

        return $fields;
    }

    /**
     * @param $list_id
     * @param $index
     *
     * @return array
     */
    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->enchargeInstance->title)
            ->set_integration_contact_fields($this->enchargeInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    public function get_list_fields_default_data()
    {
        return [
            'custom_fields' => [
                'mappable_data'       => [
                    'first_name',
                    'last_name',
                ],
                'mappable_data_types' => [
                    'text',
                    'text',
                ],
                'field_values'        => [
                    'firstName',
                    'lastName',
                ],
            ],
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

                        $field_type = fusewpVar($mappable_data_types, $index);

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($field_type == ContactFieldEntity::DATETIME_FIELD) {
                            $data = gmdate('Y-m-d H:i:s', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::BOOLEAN_FIELD) {
                            $data = filter_var($data, FILTER_VALIDATE_BOOLEAN);
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        if (fusewp_is_valid_data($data)) {
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

        $parameters = ['email' => $email_address];

        $parameters = array_merge(
            $parameters,
            array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            )
        );

        $parameters = apply_filters(
            'fusewp_encharge_subscription_parameters',
            array_filter($parameters, 'fusewp_is_valid_data'),
            $this
        );

        try {

            if ( ! empty($old_email_address) && $email_address != $old_email_address) {
                if ($userId = $this->get_user_id($old_email_address)) {
                    $parameters['id'] = $userId;
                }
            }

            // Create or update contact account - https://app-encharge-resources.s3.amazonaws.com/redoc.html#tag/People/operation/CreateUpdatePeople
            $response = $this->enchargeInstance->apiClass()->post('/people', $parameters);

            // Add tag to contact - https://app-encharge-resources.s3.amazonaws.com/redoc.html#tag/Tags/operation/AddTag
            if (fusewp_is_http_code_success($response['status_code'])) {
                $this->enchargeInstance->apiClass()->post('/tags', ['tag' => $list_id, 'email' => $email_address]);
            }

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            fusewp_log_error($this->enchargeInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @param $list_id
     * @param $email_address
     *
     * @return bool
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        $parameters = apply_filters(
            'fusewp_encharge_unsubscription_parameters',
            ['tag' => $list_id, 'email' => $email_address],
            $this, $list_id, $email_address
        );

        try {
            // https://app-encharge-resources.s3.amazonaws.com/redoc.html#tag/Tags/operation/RemoveTag
            $response = $this->enchargeInstance->apiClass()->make_request('/tags', $parameters, 'DELETE');

            return fusewp_is_http_code_success($response['status_code']);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function get_user_id($email)
    {
        try {

            // GetSpecificPeople https://app-encharge-resources.s3.amazonaws.com/redoc.html#tag/People/operation/GetSpecificPeople
            $response = $this->enchargeInstance->apiClass()->make_request('/people', [
                'people' => [
                    ['email' => $email]
                ]
            ]);

            if (fusewp_is_http_code_success($response['status_code'])) {
                $response = $response['body'];
                if (is_array($response->users) && ! empty($response->users)) {
                    return $response->users[0]->id ?? false;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->enchargeInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return false;
    }
}
