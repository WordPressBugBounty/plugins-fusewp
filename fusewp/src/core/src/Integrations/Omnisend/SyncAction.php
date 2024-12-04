<?php

namespace FuseWP\Core\Integrations\Omnisend;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Admin\Fields\Text;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $omnisendInstance;

    /**
     * @param Omnisend $omnisendInstance
     */
    public function __construct(Omnisend $omnisendInstance)
    {
        $this->omnisendInstance = $omnisendInstance;
    }

    public function get_integration_id()
    {
        return $this->omnisendInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Text($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Custom Property Value', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_placeholder(esc_html__('Enter something unique to this sync rule', 'fusewp'))
                ->set_required()
                ->set_description(sprintf(esc_html__('A custom property with the above entered value will be added to the contact so you can segment them.', 'fusewp'), '')),
            (new Text($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_placeholder(esc_html__('tag1, tag2', 'fusewp'))
                ->set_description(esc_html__('Enter a comma-separated list of tags to assign to contacts.', 'fusewp')),
            (new Custom($prefix('omnisend_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to to map custom fields to contacts.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=omnisend_sync_destination_upsell" target="_blank">', '</a>'
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

        $fields[] = (new Select($prefix('sendWelcomeEmail'), esc_html__('Welcome Email', 'fusewp')))
            ->set_db_field_id('sendWelcomeEmail')
            ->set_options(['true' => 'Enable', 'false' => 'Disable'])
            ->set_required()
            ->set_placeholder('&mdash;&mdash;&mdash;');

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->omnisendInstance->title)
            ->set_integration_contact_fields($this->omnisendInstance->get_contact_fields($list_id))
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

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        $output[$field_value] = (string)$data;
                    }
                }
            }
        }

        return $output;
    }

    /**
     * https://api-docs.omnisend.com/reference/post_contacts
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

        $sendWelcomeEmail = $GLOBALS['fusewp_sync_destination'][$list_id]['sendWelcomeEmail'] ?? 'false';

        try {
            $parameters = [
                'identifiers'      => [
                    [
                        'channels' => [
                            'email' => [
                                "status" => "subscribed",
                            ],
                        ],
                        'id'       => $email_address,
                        'type'     => 'email',
                    ],
                ],
                'tags'             => array_map('trim', explode(',', $tags)),
                'customProperties' => [$GLOBALS['fusewp_sync_source_id'] . '_segment' => trim($list_id)],
                'sendWelcomeEmail' => $sendWelcomeEmail == 'true',
            ];

            $transformed_data = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $parameters = array_merge($parameters, $transformed_data);

            $parameters = apply_filters(
                'fusewp_omnisend_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this
            );

            if ($contact_id = $this->fetch_contact($email_address)) {

                $response = $this->omnisendInstance->apiClass()->make_request("contacts/{$contact_id}", $parameters, 'patch');

            } else {
                $response = $this->omnisendInstance->apiClass()->post('contacts', $parameters);
            }

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            fusewp_log_error($this->omnisendInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $contact_id = $this->fetch_contact($email_address);

            if ( ! $contact_id) return false;

            $response = $this->omnisendInstance->apiClass()->make_request(
                "contacts/{$contact_id}",
                ['customProperties' => [$GLOBALS['fusewp_sync_source_id'] . '_segment' => '']],
                'patch'
            );

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            return false;
        }
    }

    public function fetch_contact($email_address)
    {
        try {

            $response = $this->omnisendInstance->apiClass(3)->make_request('contacts', ['email' => $email_address]);

            return $response['body']['contacts'][0]['contactID'] ?? false;

        } catch (\Exception $e) {
            return false;
        }
    }
}
