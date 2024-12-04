<?php

namespace FuseWP\Core\Integrations\CampaignMonitor;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $campaignmonitorInstance;

    /**
     * @param CampaignMonitor $campaignmonitorInstance
     */
    public function __construct($campaignmonitorInstance)
    {
        $this->campaignmonitorInstance = $campaignmonitorInstance;
    }

    public function get_integration_id()
    {
        return $this->campaignmonitorInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->campaignmonitorInstance->get_email_list())
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
            ->set_integration_name($this->campaignmonitorInstance->title)
            ->set_integration_contact_fields($this->campaignmonitorInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        if ( ! fusewp_is_premium()) {

            $fields[] = (new Custom($prefix('campaign_monitor_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map Campaign Monitor custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=campaign_monitor_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                });
        }

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

    protected function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = [];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                $first_name = '';
                $last_name  = '';

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($field_value == 'fusewpFirstName') {
                            $first_name = $data;
                            continue;
                        }

                        if ($field_value == 'fusewpLastName') {
                            $last_name = $data;
                            continue;
                        }

                        if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::DATE_FIELD) {
                            $data = gmdate('Y/m/d', fusewp_strtotime_utc($data));
                        }

                        if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::MULTISELECT_FIELD) {

                            $data = (array)$data; // ensure value is array for multi-option value

                            if (empty($data)) {

                                $output['CustomFields'][] = [
                                    'Key'   => $field_value,
                                    'Value' => '',
                                    'Clear' => 'true'
                                ];

                            } else {

                                foreach ($data as $datum) {

                                    $output['CustomFields'][] = [
                                        'Key'   => $field_value,
                                        'Value' => $datum
                                    ];
                                }
                            }

                            continue;
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        $output['CustomFields'][] = [
                            'Key'   => $field_value,
                            'Value' => $data
                        ];
                    }
                }

                if ( ! empty($first_name) || ! empty($last_name)) {
                    $output['Name'] = trim("$first_name $last_name");
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

            $properties = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity);

            $properties['EmailAddress']   = $email_address;
            $properties['Resubscribe']    = true;
            $properties['ConsentToTrack'] = 'Unchanged';

            $properties = apply_filters('fusewp_campaignmonitor_subscription_parameters', array_filter($properties, 'fusewp_is_valid_data'), $this);

            $apiClass = $this->campaignmonitorInstance->apiClass();

            $endpoint = "subscribers/$list_id.json";

            if ($is_email_change) $endpoint .= "?email=$old_email_address";

            $apiClass->apiRequest($endpoint, $is_email_change ? 'PUT' : 'POST', $properties, ['Content-Type' => 'application/json']);

            return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());

        } catch (\Exception $e) {
            fusewp_log_error($this->campaignmonitorInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $parameters = apply_filters(
                'fusewp_campaignmonitor_unsubscription_parameters', [
                'EmailAddress' => $email_address
            ],
                $this, $list_id, $email_address
            );

            $apiClass = $this->campaignmonitorInstance->apiClass();

            $apiClass->apiRequest(
                "subscribers/$list_id/unsubscribe.json",
                'POST',
                $parameters,
                ['Content-Type' => 'application/json']
            );

            return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());

        } catch (\Exception $e) {
            return false;
        }
    }
}