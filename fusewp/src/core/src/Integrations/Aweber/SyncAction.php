<?php

namespace FuseWP\Core\Integrations\Aweber;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Admin\Fields\Text;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $aweberInstance;

    /**
     * @param Aweber $aweberInstance
     */
    public function __construct($aweberInstance)
    {
        $this->aweberInstance = $aweberInstance;
    }

    public function get_integration_id()
    {
        return $this->aweberInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->aweberInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Text($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_placeholder(esc_html__('tag1, tag2', 'fusewp'))
                ->set_description(esc_html__('Enter a comma-separated list of tags to assign to contacts.', 'fusewp')),

            (new Custom($prefix('aweber_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact and map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=aweber_sync_destination_upsell" target="_blank">', '</a>'
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
            ->set_integration_name($this->aweberInstance->title)
            ->set_integration_contact_fields($this->aweberInstance->get_contact_fields($list_id))
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
        $output = [];

        $first_name = '';
        $last_name  = '';

        $custom_fields_bucket = [];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data = fusewpVar($custom_fields, 'mappable_data', []);
            $field_values  = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $aweber_field_id = $field_value;

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($aweber_field_id == 'fusewpFirstName') {
                            $first_name = $data;
                            continue;
                        }

                        if ($aweber_field_id == 'fusewpLastName') {
                            $last_name = $data;
                            continue;
                        }

                        if ($aweber_field_id == 'fusewpIPaddress') {
                            if (filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                if ($data !== '127.0.0.1' && $data !== '::1') {
                                    $output['ip_address'] = $data;
                                }
                            }
                            continue;
                        }

                        if (is_array($data)) {
                            $data = implode(', ', $data);
                        }

                        $custom_fields_bucket[$aweber_field_id] = $data;
                    }
                }
            }
        }

        $output['name']          = self::get_full_name($first_name, $last_name);
        $output['custom_fields'] = $custom_fields_bucket;

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

            $custom_field_mappings = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity);

            $properties = array_merge(
                [
                    'email'           => $email_address,
                    'update_existing' => 'true',
                    'tags'            => ! empty($tags) ? array_map('trim', explode(',', $tags)) : ''
                ],
                $custom_field_mappings
            );

            $properties = apply_filters('fusewp_aweber_subscription_parameters', array_filter($properties, 'fusewp_is_valid_data'), $this);

            $account_id = $this->aweberInstance->get_account_id();

            $is_update_request = false;

            $subscriber_id = $this->get_contact_id($email_address, $list_id);

            if ( ! empty($subscriber_id)) {
                $is_update_request = true;
            }

            if ($is_email_change) {

                $_subscriber_id = $this->get_contact_id($old_email_address, $list_id);

                if ( ! empty($_subscriber_id)) {
                    $is_update_request = true;
                    $subscriber_id     = $_subscriber_id;
                }
            }

            if ($is_update_request) {

                unset($properties['update_existing']);

                $properties['tags']['add'] = array_map('trim', explode(',', $tags));

                $properties['status'] = 'subscribed';

                $response = $this->aweberInstance->make_request(
                    "accounts/$account_id/lists/$list_id/subscribers/$subscriber_id",
                    'PATCH',
                    $properties,
                    ['Content-Type' => 'application/json']
                );
            }

            if ( ! $is_update_request) {

                $properties = array_merge(['ws.op' => 'create'], $properties);

                $response = $this->aweberInstance->make_request(
                    "accounts/$account_id/lists/$list_id/subscribers",
                    'POST',
                    array_merge(['ws.op' => 'create'], $properties),
                    ['Content-Type' => 'application/json']
                );
            }

            if (fusewp_is_http_code_success($response['status_code'])) {
                return true;
            }

            throw new \Exception(is_string($response['body']) ? $response['body'] : wp_json_encode($response['body']));

        } catch (\Exception $e) {
            fusewp_log_error($this->aweberInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

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

            $account_id = $this->aweberInstance->get_account_id();

            $parameters = apply_filters(
                'fusewp_aweber_unsubscription_parameters',
                ['status' => 'unsubscribed'],
                $this, $list_id, $email_address
            );

            $response = $this->aweberInstance->make_request(
                "accounts/$account_id/lists/$list_id/subscribers?subscriber_email=" . urlencode($email_address),
                'PATCH',
                $parameters,
                ['Content-Type' => 'application/json']
            );

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @throws \Exception
     */
    public function get_contact_id($email_address, $list_id)
    {
        try {

            $account_id = $this->aweberInstance->get_account_id();

            $response = $this->aweberInstance->make_request(
                "accounts/$account_id/lists/$list_id/subscribers",
                'GET',
                ['ws.op' => 'find', 'email' => $email_address],
                ['Content-Type' => 'application/json']
            );

            if (isset($response['body']->entries[0]->id)) {
                return $response['body']->entries[0]->id;
            }

            return false;

        } catch (\Exception $e) {
            return false;
        }
    }
}