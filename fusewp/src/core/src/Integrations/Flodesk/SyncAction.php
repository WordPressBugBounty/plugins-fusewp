<?php

namespace FuseWP\Core\Integrations\Flodesk;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $flodeskInstance;

    /**
     * @param Flodesk $flodeskInstance
     */
    public function __construct($flodeskInstance)
    {
        $this->flodeskInstance = $flodeskInstance;
    }

    public function get_integration_id()
    {
        return $this->flodeskInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Segment', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->flodeskInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;')
        ];

        if ( ! fusewp_is_premium()) {
            $fields[] = (new Custom($prefix('flodesk_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map Flodesk custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=flodesk_sync_destination_upsell" target="_blank">', '</a>'
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
            ->set_integration_name($this->flodeskInstance->title)
            ->set_integration_contact_fields($this->flodeskInstance->get_contact_fields($list_id))
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
                    'fusewpFirstName',
                    'fusewpLastName'
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

                    $data = $mappingUserDataEntity->get($mappable_data[$index]);

                    if ( ! empty($mappable_data[$index])) {

                        $flodesk_field_id = $field_value;

                        if ($flodesk_field_id == 'fusewpFirstName') {
                            $output['firstName'] = $data;
                            continue;
                        }

                        if ($flodesk_field_id == 'fusewpLastName') {
                            $output['lastName'] = $data;
                            continue;
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        $output['fields'][$flodesk_field_id] = $data;
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

            $parameters = [
                'email'        => $email_address,
                'optin_ip'     => fusewp_get_ip_address(),
                'double_optin' => fusewp_get_settings("flodesk_sync_double_optin") == "yes"
            ];

            $field_mapping = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $parameters['first_name']    = $field_mapping['firstName'];
            $parameters['last_name']     = $field_mapping['lastName'];
            $parameters['custom_fields'] = $field_mapping['fields'];

            $parameters = apply_filters(
                'fusewp_flodesk_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            $response = $this->flodeskInstance->apiClass()->make_request("subscribers", $parameters, 'post');

            if (isset($response['body']->id)) {

                $this->flodeskInstance->apiClass()->make_request(
                    sprintf("subscribers/%s/segments", $response['body']->id),
                    ['segment_ids' => [$list_id]],
                    'post'
                );

                return true;
            }

            return false;

        } catch (\Exception $e) {
            fusewp_log_error($this->flodeskInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $response = $this->flodeskInstance->apiClass()->make_request("subscribers/$email_address/segments", ['segment_ids' => [$list_id]], 'delete');

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            return false;
        }
    }
}