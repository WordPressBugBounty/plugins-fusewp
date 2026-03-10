<?php

namespace FuseWP\Core\Integrations\BentoNow;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $bentoNowInstance;

    /**
     * @param BentoNow $bentoNowInstance
     */
    public function __construct(
        BentoNow $bentoNowInstance
    )
    {
        $this->bentoNowInstance = $bentoNowInstance;
    }

    public function get_integration_id()
    {
        return $this->bentoNowInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Tag', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->bentoNowInstance->get_email_list())
                ->set_required()
                ->set_description(
                    sprintf(
                        esc_html__("Select a tag to assign to contacts. Need a different tag? go to %sBento Dashboard%s to add one.", 'fusewp'),
                        '<a target="_blank" href="https://app.bentonow.com">', '</a>'
                    )
                )
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Custom($prefix('bentonow_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=bentonow_sync_destination_upsell" target="_blank">',
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
            ->set_integration_name($this->bentoNowInstance->title)
            ->set_integration_contact_fields($this->bentoNowInstance->get_contact_fields($list_id))
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

    protected function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = [];
        $fields = [];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $data       = $mappingUserDataEntity->get($mappable_data[$index]);
                        $field_type = fusewpVar($mappable_data_types, $index);

                        if (fusewp_is_valid_data($data)) {
                            $fields[] = [
                                'key'   => $field_value,
                                'value' => is_array($data) ? implode(', ', $data) : $data
                            ];
                        }
                    }
                }
            }
        }

        if ( ! empty($fields)) {
            $output['fields'] = $fields;
        }

        return $output;
    }

    /**
     * BentoNow API: Create or update subscriber and add tags/fields
     *
     * @param $list_id (tag name in BentoNow)
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

            // Transform custom fields
            $transformed_data = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity);

            // Build subscriber object for bulk API
            $subscriber = [
                'email' => $email_address,
                'tags'  => $list_id
            ];

            // Add custom fields as direct properties
            if ( ! empty($transformed_data['fields'])) {
                foreach ($transformed_data['fields'] as $field) {
                    $subscriber[$field['key']] = $field['value'];
                }
            }

            // Handle email change: use change_email command to update subscriber in place
            if ( ! empty($old_email_address) && $email_address != $old_email_address) {

                try {

                    $this->bentoNowInstance->apiClass()->post(
                        'fetch/commands',
                        [
                            'command' => [
                                [
                                    'command' => 'change_email',
                                    'email'   => $old_email_address,
                                    'query'   => $email_address
                                ]
                            ]
                        ]
                    );

                } catch (\Exception $e) {
                    fusewp_log_error($this->bentoNowInstance->id, __METHOD__ . ': Failed to change email - ' . $e->getMessage() . '|' . $func_args);
                }
            }

            $this->bentoNowInstance->apiClass()->post(
                'batch/subscribers',
                ['subscribers' => [$subscriber]]
            );

            return true;

        } catch (\Exception $e) {

            fusewp_log_error($this->bentoNowInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $this->bentoNowInstance->apiClass()->post(
                'batch/subscribers',
                [
                    'subscribers' => [
                        [
                            'email'       => $email_address,
                            'remove_tags' => $list_id
                        ]
                    ]
                ]
            );

            return true;

        } catch (\Exception $e) {

            return false;
        }
    }
}
