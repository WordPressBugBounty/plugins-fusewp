<?php

namespace FuseWP\Core\Integrations\Drip;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $dripInstance;

    /**
     * @param Drip $dripInstance
     */
    public function __construct(
        Drip $dripInstance
    )
    {
        $this->dripInstance = $dripInstance;
    }

    public function get_integration_id()
    {
        return $this->dripInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Tag', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->dripInstance->get_email_list())
                ->set_description(
                    sprintf(
                        esc_html__("Select the tag to assign to contact. Can't find the appropriate tag, %sclick here%s to add one inside Drip", 'fusewp'),
                        '<a target="_blank" href="https://www.getdrip.com/' . $this->dripInstance->accountId . '/people/properties#tags">', '</a>'
                    )
                )
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Custom($prefix('drip_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=drip_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
            unset($fields[1]);
        }

        return $fields;
    }

    /**
     * https://developer.drip.com/?shell#subscribe-someone-to-an-email-series-campaign
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
        $response  = [];
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $is_email_change = ! empty($old_email_address) && $email_address != $old_email_address;

            $properties = [
                'email' => $email_address,
                "tags"  => [$list_id],
            ];

            $transformed_data = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $properties = apply_filters(
                'fusewp_drip_subscription_parameters',
                array_filter(array_merge($properties, $transformed_data), 'fusewp_is_valid_data'),
                $this
            );

            $update_flag = false;

            if ($is_email_change) {

                $update_flag = true;

                $properties['email']     = $old_email_address;
                $properties['new_email'] = $email_address;

                $response = $this->dripInstance->apiClass()->post("subscribers", ['subscribers' => [$properties]]);
            }

            if ( ! $update_flag) {
                $response = $this->dripInstance->apiClass()->post("subscribers", ['subscribers' => [$properties]]);
            }

            if (isset($response['body']['subscribers'][0]['id'])) {
                return true;
            }

            fusewp_log_error($this->dripInstance->id, __METHOD__ . ':' . is_string($response['body']) ? $response : wp_json_encode($response['body']));

            return false;

        } catch (\Exception $e) {
            fusewp_log_error($this->dripInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

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
        try {

            $response = $this->dripInstance->apiClass()->make_request("subscribers/$email_address/tags/$list_id", [], 'delete');

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            return false;
        }
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
                    'first_name',
                    'last_name',
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

                        $drip_field_id = $field_value;

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($field_type == ContactFieldEntity::BOOLEAN_FIELD) {
                            $data = filter_var($data, FILTER_VALIDATE_BOOLEAN);
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        if (strstr($drip_field_id, 'drip_custom_') !== false) {

                            $output['custom_fields'][str_replace('drip_custom_', '', $drip_field_id)] = $data;

                        } else {
                            $output[$drip_field_id] = $data;
                        }
                    }
                }
            }
        }

        return $output;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->dripInstance->title)
            ->set_integration_contact_fields($this->dripInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    public function get_workflows()
    {
        $options = [];

        try {

            $response = $this->dripInstance->apiClass()->make_request('workflows');

            if (isset($response['body']['workflows'])) {

                $options = array_reduce($response['body']['workflows'], function ($carry, $item) {
                    $carry[$item['id']] = $item['name'];

                    return $carry;
                }, []);
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->dripInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $options;
    }
}
