<?php

namespace FuseWP\Core\Integrations\OrttoCRM;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Admin\Fields\Text;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity as CFE;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $orttocrmInstance;

    /**
     * @param OrttoCRM $orttocrmInstance
     */
    public function __construct(OrttoCRM $orttocrmInstance)
    {
        $this->orttocrmInstance = $orttocrmInstance;
    }

    /**
     * @return mixed
     */
    public function get_integration_id()
    {
        return $this->orttocrmInstance->id;
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
            (new Text($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_required()
                ->set_placeholder(esc_html__('tag1, tag2', 'fusewp'))
                ->set_description(esc_html__('Enter a comma-separated list of tags to assign to contacts.', 'fusewp')),
            (new Select($prefix('organization'), esc_html__('Select Organization', 'fusewp')))
                ->set_db_field_id('organization')
                ->set_options($this->orttocrmInstance->get_organization_list())
                ->set_description(esc_html__('Select the organization to associate contacts to.', 'fusewp')),
            (new Custom($prefix('orttocrm_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields and add contacts to a specific organization.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=orttocrm_sync_destination_upsell" target="_blank">',
                            '</a>'
                        ) . '</p>';
                }),
        ];

        if ( ! fusewp_is_premium()) {
            unset($fields[1]);
        } else {
            unset($fields[2]);
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
            ->set_integration_name($this->orttocrmInstance->title)
            ->set_integration_contact_fields($this->orttocrmInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    /**
     * @return array[]
     */
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
                    'str::first',
                    'str::last'
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

                    if ( ! empty($mappable_data[$index])) {

                        $field_type = fusewpVar($mappable_data_types, $index);
                        $data       = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ( ! fusewp_is_valid_data($data)) continue;

                        $rawVal = $data;

                        if (is_array($data)) $data = implode(', ', $data);

                        if ($field_type == CFE::BOOLEAN_FIELD) {
                            $data = filter_var($rawVal, FILTER_VALIDATE_BOOLEAN);
                        }

                        if ($field_type == CFE::NUMBER_FIELD) {
                            $data = absint($rawVal);
                        }

                        if (in_array($field_type, [CFE::DATE_FIELD, CFE::DATETIME_FIELD])) {
                            $data = gmdate('c', fusewp_strtotime_utc($rawVal));
                        }

                        if ($field_type == CFE::MULTISELECT_FIELD) {
                            $data = array_map('trim', (array)$rawVal);
                        }

                        if (strpos($field_value, 'phn::') === 0) {
                            $data = [
                                'phone'                   => $rawVal,
                                'parse_with_country_code' => true,
                            ];
                        }

                        if (strpos($field_value, 'geo::') === 0) {
                            $data = ['name' => $rawVal];
                        }

                        if (strpos($field_value, 'dtz::b') === 0) {
                            $timestamp = fusewp_strtotime_utc($rawVal);
                            $data      = [
                                'day'      => absint(gmdate('d', $timestamp)),
                                'month'    => absint(gmdate('m', $timestamp)),
                                'year'     => absint(gmdate('Y', $timestamp)),
                                'timezone' => gmdate('e', $timestamp),
                            ];
                        }

                        $output[$field_value] = $data;
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

            $is_email_change = ! empty($old_email_address) && $email_address != $old_email_address;

            $fields   = ['str::email' => $email_address];
            $merge_by = ['str::email'];

            if ($is_email_change) {
                if ($contact = $this->find_contact_by_email($old_email_address)) {
                    $fields['str::person_id'] = $contact['id'];
                    // person_id merge key can only be used alone, that's why we override and not update
                    $merge_by = ['str::person_id'];
                }
            }

            $fields = apply_filters(
                'fusewp_orttocrm_subscription_parameters',
                array_filter(array_merge($fields, $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity)), 'fusewp_is_valid_data'),
                $this
            );

            $parameters = [
                'people'   => [
                    [
                        'fields' => $fields,
                        'tags'   => array_map('trim', explode(',', $list_id)),
                    ]
                ],
                'merge_by' => $merge_by
            ];

            $response = $this->orttocrmInstance->apiClass()->post('person/merge', $parameters);

            if (fusewp_is_http_code_success($response['status_code'])) {

                $organization = $GLOBALS['fusewp_sync_destination'][$list_id]['organization'];

                try {

                    $org_params = [
                        'inclusion_ids'   => [
                            $response['body']['people'][0]['person_id']
                        ],
                        'organization_id' => $organization,
                    ];

                    $this->orttocrmInstance->apiClass()->make_request('organizations/contacts/add', $org_params, 'put');

                } catch (\Exception $e) {

                    fusewp_log_error($this->orttocrmInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);
                }
            }

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {

            fusewp_log_error($this->orttocrmInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @param $email_address
     *
     * @return mixed|null
     */
    public function find_contact_by_email($email_address)
    {
        try {
            $response = $this->orttocrmInstance->apiClass()->post('person/get', [
                'limit'  => 1,
                'fields' => ['str::id', 'str::email', 'str::first', 'str::last'],
                'filter' => [
                    '$str::is' => [
                        'field_id' => 'str::email',
                        'value'    => $email_address
                    ]
                ]
            ]);

            $response = $response['body'] ?? [];

            if ( ! empty($response['contacts'])) return $response['contacts'][0];

            return false;

        } catch (\Exception $e) {

            fusewp_log_error($this->orttocrmInstance->id, __METHOD__ . ':' . $e->getMessage());

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
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $parameters = [
                'people'   => [
                    [
                        'fields'     => ['str::email' => $email_address],
                        'unset_tags' => array_map('trim', explode(',', $list_id)),
                    ]
                ],
                'merge_by' => ['str::email']
            ];

            $response = $this->orttocrmInstance->apiClass()->post('person/merge', $parameters);

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {

            fusewp_log_error($this->orttocrmInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }
}
