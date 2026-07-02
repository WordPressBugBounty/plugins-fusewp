<?php

namespace FuseWP\Core\Integrations\Copper;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Admin\Fields\Text;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $copperInstance;

    /**
     * @param copper $copperInstance
     */
    public function __construct($copperInstance)
    {
        $this->copperInstance = $copperInstance;
    }

    public function get_integration_id()
    {
        return $this->copperInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Record Type', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->copperInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Text($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_placeholder(esc_html__('tag1, tag2', 'fusewp'))
                ->set_description(esc_html__('Enter a comma-separated list of tags to assign to contacts.', 'fusewp')),
            (new Custom($prefix('copper_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact and map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=copper_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                })
        ];

        if (!fusewp_is_premium()) {
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
            ->set_integration_name($this->copperInstance->title)
            ->set_integration_contact_fields($this->copperInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    public function get_list_fields_default_data()
    {
        return [
            'custom_fields' => [
                'mappable_data' => [
                    'first_name',
                    'last_name'
                ],
                'mappable_data_types' => [
                    'text',
                    'text'
                ],
                'field_values' => [
                    'first_name',
                    'last_name'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = [];

        $custom_field_option_ids = get_option("fusewp_copper_custom_field_option_ids", []);

        if (is_array($custom_fields) && !empty($custom_fields)) {

            $mappable_data = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && !empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if (!empty($mappable_data[$index])) {

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);
                        $mapped_data_type = fusewpVar($mappable_data_types, $index);

                        if ($mapped_data_type == ContactFieldEntity::DATE_FIELD) {
                            $data = fusewp_strtotime_utc($data);
                        }

                        // Copper API accept only the option IDs (options->id) of multiselect and  Dropdown fields which is always is interger/numeric
                        // {#4910 ▼
                        //    +"id": 753779
                        //    +"name": "Multiselect"
                        //    +"data_type": "MultiSelect"
                        //    +"available_on": array:2 [▶]
                        //    +"is_filterable": true
                        //    +"options": array:3 [▼
                        //      0 => {#4906 ▼
                        //        +"id": 2223604
                        //        +"name": "subscriber"
                        //        +"rank": 0
                        //      }
                        //      1 => {#4911 ▼
                        //        +"id": 2223605
                        //        +"name": "administrator"
                        //        +"rank": 1
                        //      }
                        //      2 => {#4912 ▼
                        //        +"id": 2223606
                        //        +"name": "editor"
                        //        +"rank": 2
                        //      }
                        //    ]
                        //  }
                        if ($mapped_data_type == ContactFieldEntity::NUMBER_FIELD) {
                            $data = absint($data);
                        }

                        if (is_array($data) && $mapped_data_type != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        if (strstr($field_value, 'phone_numbers.')) {
                            $__explode = explode('.', $field_value);

                            $output['phone_numbers'][] = [
                                'number' => (string)$data,
                                'category' => $__explode[1]
                            ];
                            continue;
                        }

                        if (strstr($field_value, 'websites.')) {
                            $__explode = explode('.', $field_value);

                            $output['websites'][] = [
                                'url' => (string)$data,
                                'category' => $__explode[1]
                            ];
                            continue;
                        }

                        if (strstr($field_value, 'socials.')) {
                            $__explode = explode('.', $field_value);

                            $output['socials'][] = [
                                'url' => (string)$data,
                                'category' => $__explode[1]
                            ];
                            continue;
                        }

                        if (strstr($field_value, 'address.')) {
                            $_explode = explode('.', $field_value);
                            $output['address'][$_explode[1]] = $data;
                            continue;
                        }

                        if (strstr($field_value, 'fwpcpcus_')) {

                            $field_id_combo = str_replace('fwpcpcus_', '', $field_value);
                            [$fieldId, $fieldType] = explode('|', $field_id_combo);

                            $valid_option_ids = $custom_field_option_ids[$fieldId] ?? [];

                            if ($fieldType == 'MultiSelect' && is_array($data) && !empty($data)) {

                                // ensure value for both fieldTypes is an actual valid option ID.
                                $__result = array_filter($data, function ($value) use ($valid_option_ids) {
                                    return in_array($value, $valid_option_ids);
                                });

                                if (empty($__result)) continue;

                                $data = array_map('absint', $__result);
                            }

                            if ($fieldType == 'Dropdown') {
                                $data = absint($data);
                                // ensure value for both fieldTypes is an actual valid option ID.
                                if (!in_array($data, $valid_option_ids)) continue;
                            }

                            // see comment above. Percentage field in Copper is integer
                            if ($fieldType == 'Percentage') $data = absint($data);
                            if ($fieldType == 'Float') $data = $this->castFloatSmart($data);
                            if ($fieldType == 'Date' && !is_numeric($data)) $data = fusewp_strtotime_utc($data);

                            $output['custom_fields'][] = [
                                'custom_field_definition_id' => $fieldId,
                                'value' => $data
                            ];

                            continue;
                        }

                        $output[$field_value] = $data;
                    }
                }
            }
        }

        $output['name'] = $output['first_name'] . ' ' . $output['last_name']; // Copper API requires a name.

        return $output;
    }

    /**
     * {@inheritdoc}
     *
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        $is_email_change = !empty($old_email_address) && $email_address != $old_email_address;

        try {

            if ($list_id === 'leads') {

                $properties = [
                    'email' => [
                        'email' => $email_address,
                        'category' => 'work'
                    ]
                ];

            } else {

                $properties = [
                    'emails' => [
                        [
                            'email' => $email_address,
                            'category' => 'work'
                        ]
                    ]
                ];
            }

            $other_props = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $properties = array_merge($properties, $other_props);

            if (!empty($tags)) {
                $properties['tags'] = array_map('trim', explode(',', $tags));
            }

            $properties = apply_filters(
                'fusewp_copper_subscription_parameters',
                $properties,
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            $lookup_email_address = $is_email_change ? $old_email_address : $email_address;

            $contact = $this->is_contact_exist($lookup_email_address, $list_id);

            if ($contact) {

                if (isset($properties['tags'])) {
                    $properties['tags'] = array_merge($contact->tags, $properties['tags']);
                }

                $response = $this->copperInstance->apiClass()->apiRequest(
                    $list_id . '/' . $contact->id,
                    'PUT',
                    $properties,
                    ['Content-Type' => 'application/json']
                );

            } else {

                $response = $this->copperInstance->apiClass()->apiRequest(
                    $list_id,
                    'POST',
                    $properties,
                    ['Content-Type' => 'application/json']
                );
            }

            if (!empty($response->id)) return true;

            throw new \Exception(__METHOD__ . ':' . is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {
            fusewp_log_error($this->copperInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        $tags = $GLOBALS['fusewp_sync_destination'][$list_id]['tags'];

        if (!empty($tags) && !empty($email_address)) {

            $tags = array_map('trim', explode(',', $tags));

            $contact = $this->is_contact_exist($email_address, $list_id);

            if (!empty($tags) && is_array($contact->tags) && !empty($contact->tags)) {

                $_result = array_diff($contact->tags, $tags);

                try {

                    $response = $this->copperInstance->apiClass()->apiRequest(
                        $list_id . '/' . $contact->id,
                        'PUT',
                        ['tags' => $_result],
                        ['Content-Type' => 'application/json']
                    );

                    return isset($response->id);

                } catch (\Exception $e) {
                }
            }
        }

        return false;
    }

    private function is_contact_exist($email_address, $record_type)
    {
        if (!empty($email_address)) {

            try {

                if ($record_type == 'leads') {

                    $response = $this->copperInstance->apiClass()->apiRequest(
                        'leads/search',
                        'POST',
                        ['emails' => $email_address, 'page_size' => 1],
                        ['Content-Type' => 'application/json']
                    );

                    if (is_array($response) && isset($response[0]->id)) return $response[0];

                } else {

                    $response = $this->copperInstance->apiClass()->apiRequest(
                        'people/fetch_by_email',
                        'POST',
                        ['email' => $email_address],
                        ['Content-Type' => 'application/json']
                    );

                    if (!empty($response->id)) return $response;
                }

            } catch (\Exception $e) {
            }
        }

        return false;
    }

    private function castFloatSmart($value)
    {
        $floatVal = (float)$value;
        return ($floatVal == (int)$floatVal) ? (int)$floatVal : $floatVal;
    }
}