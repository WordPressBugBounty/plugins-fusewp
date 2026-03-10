<?php

namespace FuseWP\Core\Integrations\Birdsend;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $birdsendInstance;

    public function __construct(Birdsend $birdsendInstance)
    {
        $this->birdsendInstance = $birdsendInstance;
    }

    /**
     * @return mixed
     */
    public function get_integration_id()
    {
        return $this->birdsendInstance->id;
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
                ->set_options($this->birdsendInstance->get_email_list())
                ->set_description(
                    sprintf(
                        esc_html__("Select the tag to assign to contact. Can't find the appropriate tag, %sclick here%s to add one inside Birdsend.", 'fusewp'),
                        '<a target="_blank" href="https://app.birdsend.co/user/tags">', '</a>'
                    )
                )
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Custom($prefix('birdsend_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=birdsend_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
            unset($fields[1]);
        }

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

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if (is_array($data)) $data = implode(', ', $data);
                        // All other fields go in the fields object
                        $output[$field_value] = $data;
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
            ->set_integration_name($this->birdsendInstance->title)
            ->set_integration_contact_fields($this->birdsendInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
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

            $transformed_data = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity);

            $request_data = [
                'email'     => $email_address,
                'ipaddress' => fusewp_get_ip_address()
            ];

            $fields = array_filter($transformed_data, 'fusewp_is_valid_data');

            if ( ! empty($fields)) $request_data['fields'] = $fields;

            $request_data = apply_filters('fusewp_birdsend_subscription_parameters', $request_data, $this);

            $email = ! empty($old_email_address) ? $old_email_address : $email_address;

            $tag_name = explode(':', $list_id, 2)[1] ?? '';

            if (empty($tag_name)) return false;

            $contact_id = $this->get_contact_id_by_email($email);

            // update contact if email changed
            if ($is_email_change && $contact_id) {
                $this->birdsendInstance->apiClass()->apiRequest("contacts/{$contact_id}", 'PATCH', $request_data);
            }

            $apiClass = $this->birdsendInstance->apiClass();

            if ($contact_id) {

                // add tag to existing contact - requires contact to be subscribed.
                $apiClass->apiRequest("contacts/{$contact_id}/tags", 'POST', [
                    "tags" => [$tag_name]
                ]);

            } else {
                // create new contact with tag
                $request_data['tags'] = [$tag_name];

                $apiClass->apiRequest("contacts", 'POST', $request_data);
            }

            return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());

        } catch (\Exception $e) {

            fusewp_log_error($this->birdsendInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

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
        $contact_id = $this->get_contact_id_by_email($email_address);

        try {

            if ($contact_id) {
                $tag_id   = explode(':', $list_id, 2)[0] ?? $list_id;
                $apiClass = $this->birdsendInstance->apiClass();
                $apiClass->apiRequest("contacts/$contact_id/tags/$tag_id", 'DELETE', []);

                return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());
            }

            return false;

        } catch (\Exception $e) {

            fusewp_log_error($this->birdsendInstance->id, __METHOD__ . ':' . $e->getMessage());

            return false;
        }
    }

    private function get_contact_id_by_email($email)
    {
        try {
            $response = $this->birdsendInstance->apiClass()->apiRequest("contacts", 'GET', [
                'search_by' => 'email',
                'keyword'   => $email
            ]);

            $data = $response->data ?? [];

            if ( ! empty($data)) {
                foreach ($data as $contact) {
                    if ($contact->email == $email) {
                        return $contact->contact_id;
                    }
                }
            }

        } catch (\Exception $e) {
        }

        return false;
    }
}
