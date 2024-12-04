<?php

namespace FuseWP\Core\Integrations\HighLevel;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $highlevelInstance;

    /**
     * @param HighLevel $highlevelInstance
     */
    public function __construct($highlevelInstance)
    {
        $this->highlevelInstance = $highlevelInstance;
    }

    public function get_integration_id()
    {
        return $this->highlevelInstance->id;
    }

    public function get_workflows()
    {
        $options = [];

        try {

            $response = $this->highlevelInstance->make_request('workflows/?locationId={locationId}');

            if (isset($response->workflows)) {

                $options = array_reduce($response->workflows, function ($carry, $item) {
                    $carry[$item->id] = $item->name;

                    return $carry;
                }, []);
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->highlevelInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $options;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Tag', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->highlevelInstance->get_email_list())
                ->set_description(
                    sprintf(
                        esc_html__("Select the tag to assign to contact. Can't find the appropriate tag, %sclick here%s to add one inside HighLevel", 'fusewp'),
                        '<a href="https://app.gohighlevel.com/v2/location/' . $this->highlevelInstance->locationId . '/settings/tags">', '</a>'
                    )
                )
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix('workflow'), esc_html__('Workflow', 'fusewp')))
                ->set_db_field_id('workflow')
                ->set_options($this->get_workflows())
                ->set_placeholder('&mdash;&mdash;&mdash;')
                ->set_description(esc_html__('Select a workflow to add contact to', 'fusewp')),
            (new Custom($prefix('highlevel_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to add contacts to workflows and map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=highlevel_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                })
        ];

        if ( ! fusewp_is_premium()) {
            unset($fields[1]);
        } else {
            unset($fields[2]);
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->highlevelInstance->title)
            ->set_integration_contact_fields($this->highlevelInstance->get_contact_fields($list_id))
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

                        $field_type = fusewpVar($mappable_data_types, $index);

                        $highlevel_field_id = $field_value;

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($highlevel_field_id == 'fusewpFirstName') {
                            $first_name = $data;
                            continue;
                        }

                        if ($highlevel_field_id == 'fusewpLastName') {
                            $last_name = $data;
                            continue;
                        }

                        if ($field_type == ContactFieldEntity::NUMBER_FIELD) {
                            $data = intval($data);
                        }

                        if ($field_type == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = (array)$data;
                        }

                        if (is_array($data) && $field_type != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        // this ensure data is a string as it might throw error if it isn't.
                        if ( ! is_array($data)) {
                            $data = (string)$data;
                        }

                        if (strstr($highlevel_field_id, 'ghl_custom_') !== false) {
                            $output['customFields'][] = [
                                'id'          => str_replace('ghl_custom_', '', $highlevel_field_id),
                                'field_value' => $data
                            ];
                        } else {
                            $output[$highlevel_field_id] = $data;
                        }
                    }
                }
            }
        }

        $output['name'] = trim($first_name . ' ' . $last_name);

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

            $properties = [
                'email'      => $email_address,
                'locationId' => '{locationId}'
            ];

            $workflow = $GLOBALS['fusewp_sync_destination'][$list_id]['workflow'];

            $transformed_data = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $properties = apply_filters(
                'fusewp_highlevel_subscription_parameters',
                array_filter(array_merge($properties, $transformed_data), 'fusewp_is_valid_data'),
                $this
            );

            $update_flag = false;

            if ($is_email_change) {

                $contact_id = $this->get_contact_id($old_email_address);

                if ( ! empty($contact_id)) {

                    $update_flag = true;

                    unset($properties['locationId']);

                    $response = $this->highlevelInstance->make_request(
                        "contacts/$contact_id",
                        'PUT',
                        $properties
                    );
                }

            }

            if ( ! $update_flag) {

                $response = $this->highlevelInstance->make_request(
                    'contacts/upsert',
                    'POST',
                    $properties
                );
            }

            if (isset($response->contact->id)) {

                $this->add_tags_to_contact($response->contact->id, $list_id);
                $this->add_contact_to_workflow($response->contact->id, $workflow);

                return true;
            }

            throw new \Exception(__METHOD__ . ':' . is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {
            fusewp_log_error($this->highlevelInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $contact_id = $this->get_contact_id($email_address);

            if ( ! empty($contact_id) && ! empty($list_id)) {

                $this->highlevelInstance->make_request(
                    "contacts/{$contact_id}/tags",
                    'DELETE',
                    ['tags' => [$list_id]]
                );

                return true;
            }

        } catch (\Exception $e) {
        }

        return false;
    }

    protected function add_tags_to_contact($contact_id, $lead_tag)
    {
        try {

            if ( ! empty($lead_tag)) {

                $this->highlevelInstance->make_request(
                    "contacts/{$contact_id}/tags",
                    'POST',
                    ['tags' => [$lead_tag]]
                );
            }

        } catch (\Exception $e) {

        }
    }

    public function add_contact_to_workflow($contact_id, $workflow_id)
    {
        try {

            $this->highlevelInstance->make_request(
                "contacts/{$contact_id}/workflow/{$workflow_id}",
                'POST'
            );

        } catch (\Exception $e) {

        }
    }

    public function get_contact_id($email)
    {
        try {

            $response = $this->highlevelInstance->make_request(
                "contacts/",
                'GET',
                ['locationId' => '{locationId}', 'query' => $email]
            );

            return $response->contacts[0]->id ?? false;

        } catch (\Exception $e) {

        }

        return false;
    }
}