<?php

namespace FuseWP\Core\Integrations\Mailchimp;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Admin\Fields\Text;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $mailchimpInstance;

    /**
     * @param Mailchimp $mailchimpInstance
     */
    public function __construct($mailchimpInstance)
    {
        $this->mailchimpInstance = $mailchimpInstance;
    }

    public function get_integration_id()
    {
        return $this->mailchimpInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Audience', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->mailchimpInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Text($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_placeholder(esc_html__('tag1, tag2', 'fusewp'))
                ->set_description(esc_html__('Enter a comma-separated list of tags to assign to contacts.', 'fusewp')),
            (new Custom($prefix('mailchimp_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact and map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=mailchimp_sync_destination_upsell" target="_blank">', '</a>'
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
            ->set_integration_name($this->mailchimpInstance->title)
            ->set_integration_contact_fields($this->mailchimpInstance->get_contact_fields($list_id))
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
                    'FNAME',
                    'LNAME'
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

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if (strpos($field_value, '|') !== false) {

                            $key_parts = explode('|', $field_value);

                            if ( ! isset($output[$key_parts[0]])) {
                                // All address fields are required, hence the default values below.
                                $output[$key_parts[0]] = [
                                    'addr1' => '-',
                                    'city'  => '-',
                                    'zip'   => '-',
                                    'state' => '-',
                                ];
                            }

                            $output[$key_parts[0]][$key_parts[1]] = $data;

                        } else {

                            if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::DATE_FIELD) {
                                $data = gmdate('m/d/Y', fusewp_strtotime_utc($data));
                            }

                            if (is_array($data)) {
                                $data = implode(', ', $data);
                            }

                            $output[$field_value] = $data;
                        }
                    }
                }
            }
        }

        return $output;
    }

    private function is_double_optin()
    {
        return fusewp_get_settings('mailchimp_sync_double_optin') == 'yes';
    }

    /**
     * {@inheritdoc}
     *
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $main_email = ! empty($old_email_address) ? $old_email_address : $email_address;

            $parameters = [
                'email_address' => $email_address,
                'status_if_new' => $this->is_double_optin() ? 'pending' : 'subscribed',
                'status'        => 'subscribed',
                'merge_fields'  => array_filter(
                    $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                    'fusewp_is_valid_data'
                )
            ];

            $parameters = apply_filters(
                'fusewp_mailchimp_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            $response = $this->mailchimpInstance->apiClass()->apiRequest(
                sprintf('/lists/%s/members/%s', $list_id, md5(strtolower($main_email))),
                'PUT',
                $parameters,
                ['Content-Type' => 'application/json']
            );

            if (isset($response->id)) {

                $this->add_member_tag($email_address, $list_id, $tags);

                return true;
            }

            throw new \Exception(__METHOD__ . ':' . is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {
            fusewp_log_error($this->mailchimpInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

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

            $tags = $GLOBALS['fusewp_sync_destination'][$list_id]['tags'];

            if ($this->is_tag_segmentation()) {
                return $this->add_member_tag($email_address, $list_id, $tags, true);
            }

            $parameters = apply_filters(
                'fusewp_mailchimp_unsubscription_parameters',
                ['status' => 'unsubscribed'],
                $this, $list_id, $email_address
            );

            $apiClass = $this->mailchimpInstance->apiClass();

            $apiClass->apiRequest(
                sprintf('/lists/%s/members/%s', $list_id, md5(strtolower($email_address))),
                'PATCH',
                $parameters,
                ['Content-Type' => 'application/json']
            );

            return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $email_address
     * @param string $list_id
     * @param string $tags
     * @param bool $is_remove set to true to remove tags instead
     *
     * @return bool
     */
    protected function add_member_tag($email_address, $list_id, $tags, $is_remove = false)
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        if(empty($tags)) return false;

        try {

            $tags = array_map(function ($tag) use ($is_remove) {
                return [
                    'name'   => trim($tag),
                    'status' => $is_remove === true ? 'inactive' : 'active',
                ];
            },
                explode(',', $tags)
            );

            $parameters = apply_filters(
                'fusewp_mailchimp_add_member_tag_parameters',
                ['tags' => $tags],
                $this, $list_id, $email_address, $tags
            );

            $apiClass = $this->mailchimpInstance->apiClass();

            $apiClass->apiRequest(
                sprintf('/lists/%s/members/%s/tags', $list_id, md5(strtolower($email_address))),
                'POST',
                $parameters,
                ['Content-Type' => 'application/json']
            );

            return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());

        } catch (\Exception $e) {
            fusewp_log_error($this->mailchimpInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    protected function is_tag_segmentation()
    {
        return apply_filters('fusewp_sync_mailchimp_is_tag_segmentation', false, $this);
    }
}