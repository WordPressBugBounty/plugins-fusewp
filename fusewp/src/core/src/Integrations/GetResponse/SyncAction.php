<?php

namespace FuseWP\Core\Integrations\GetResponse;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $getResponseInstance;

    /**
     * @param GetResponse $getResponseInstance
     */
    public function __construct(
        GetResponse $getResponseInstance
    )
    {
        $this->getResponseInstance = $getResponseInstance;
    }

    public function get_integration_id()
    {
        return $this->getResponseInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->getResponseInstance->get_email_list())
                ->set_required()
                ->set_description(
                    sprintf(
                        esc_html__("Select a tag to assign to contacts. Need a different list?, %sclick here%s to add one on GetResponse dashboard.",
                            'fusewp'),
                        '<a target="_blank" href="https://app.getresponse.com/lists">', '</a>'
                    )
                )
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix(self::TAGS_FIELD_ID), esc_html__('Select Tag', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_options($this->getResponseInstance->get_tags_list())
                ->set_description(
                    sprintf(
                        esc_html__("Select a tag to assign to contacts. Can't find the appropriate tag, %sclick here%s to add one on GetResponse.",
                            'fusewp'),
                        '<a target="_blank" href="https://app.getresponse.com/tags">', '</a>'
                    )
                )
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Custom($prefix('getresponse_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=getresponse_sync_destination_upsell" target="_blank">',
                            '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
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
            ->set_integration_name($this->getResponseInstance->title)
            ->set_integration_contact_fields($this->getResponseInstance->get_contact_fields($list_id))
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

    protected function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        // phone custom field accept number plus prefix eg +14342990186 or +2348234678345
        // country accept full country name eg Nigeria, United Kingdom
        $output = ['customFieldValues' => []];

        $first_name = '';
        $last_name  = '';

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        $field_type = fusewpVar($mappable_data_types, $index);

                        if ($field_value == 'fusewpFirstName') {
                            $first_name = $data;
                            continue;
                        }

                        if ($field_value == 'fusewpLastName') {
                            $last_name = $data;
                            continue;
                        }

                        if ($field_value == 'fusewpIPAddress') {
                            // supports ipv6
                            if (filter_var($data, FILTER_VALIDATE_IP)) {
                                if ($data !== '127.0.0.1' && $data !== '::1') {
                                    $output['ipAddress'] = $data;
                                }
                            }
                            continue;
                        }

                        if ($field_type == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::DATETIME_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d H:i:s', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = (array)$data;
                        }

                        if (is_array($data) && $field_type != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        if (fusewp_is_valid_data($data)) {
                            $output['customFieldValues'][] = [
                                'customFieldId' => $field_value,
                                'value'         => is_array($data) ? $data : [$data]
                            ];
                        }
                    }
                }
            }
        }

        $output['name'] = self::get_full_name($first_name, $last_name);

        return $output;
    }

    /**
     * https://apireference.getresponse.com/#operation/createContact
     * @param $list_id
     * @param $email_address
     * @param $mappingUserDataEntity
     * @param $custom_fields
     * @param $tags
     * @param $old_email_address
     *
     * @return false|void
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $main_email = ! empty($old_email_address) ? $old_email_address : $email_address;

            $parameters = [
                'email'    => $email_address,
                'campaign' => ['campaignId' => $list_id],
                'tags'     => ['tagId' => $tags]
            ];

            $parameters = array_merge($parameters, array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            ));

            $parameters = apply_filters(
                'fusewp_getresponse_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this
            );

            if ($contact_id = $this->getContactId($main_email)) {

                $response = $this->getResponseInstance->apiClass()->post(
                    "contacts/$contact_id",
                    $parameters,
                );

            } else {
                $response = $this->getResponseInstance->apiClass()->post(
                    'contacts',
                    $parameters,
                );
            }

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {

            fusewp_log_error($this->getResponseInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $contactId = $this->getContactId($email_address);
            $tag       = $GLOBALS['fusewp_sync_destination'][$list_id]['tags'];

            if ( ! $contactId || ! $tag) return false;

            $response = $this->getResponseInstance->apiClass()->make_request("contacts/$contactId");

            $existing_tags = isset($response['body']['tags']) ? $response['body']['tags'] : [];

            // remove our tag from the contact existing tags
            $filtered_tags = array_filter($existing_tags, function ($val) use ($tag) {
                return $val['tagId'] !== $tag;
            });

            $contactTags = array_values(array_map(fn($val) => ['tagId' => $val['tagId']], $filtered_tags));

            $response = $this->getResponseInstance->apiClass()->post(
                "contacts/{$contactId}",
                ['tags' => $contactTags],
            );

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
        }

        return false;
    }

    protected function getContactId($email)
    {
        try {

            $response = $this->getResponseInstance->apiClass()->make_request('contacts', ['query[email]' => $email]);

            if (is_array($response['body']) && ! empty($response['body'])) {
                return $response['body'][0]['contactId'];
            }

        } catch (\Exception $e) {
        }

        return false;
    }
}
