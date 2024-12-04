<?php

namespace FuseWP\Core\Integrations\ConvertKit;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $convertkitInstance;

    /**
     * @param ConvertKit $convertkitInstance
     */
    public function __construct($convertkitInstance)
    {
        $this->convertkitInstance = $convertkitInstance;
    }

    public function get_integration_id()
    {
        return $this->convertkitInstance->id;
    }

    /**
     * @return array
     */
    public function get_tags()
    {
        $tags = [];

        try {

            $response = $this->convertkitInstance->apiClass()->get_tags();

            if (isset($response['body']->tags)) {
                foreach ($response['body']->tags as $tag) {
                    $tags[$tag->id] = $tag->name;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->convertkitInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $tags;
    }

    /**
     * @return array
     */
    public function get_sequences()
    {
        $sequences = [];

        try {

            $response = $this->convertkitInstance->apiClass()->get_sequences();

            if (isset($response['body']->courses)) {
                foreach ($response['body']->courses as $sequence) {
                    $sequences[$sequence->id] = $sequence->name;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->convertkitInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $sequences;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Form', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->convertkitInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_is_multiple()
                ->set_options($this->get_tags())
                ->set_description(esc_html__('Select the tags to assign to contacts.', 'fusewp')),
            (new Select($prefix('sequences'), esc_html__('Sequences', 'fusewp')))
                ->set_db_field_id('sequences')
                ->set_is_multiple()
                ->set_options($this->get_sequences())
                ->set_description(esc_html__('Select the sequences to subscribe contacts to.', 'fusewp')),
            (new Custom($prefix('convertkit_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact, select sequence to and more.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=convertkit_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                })
        ];

        if ( ! fusewp_is_premium()) {
            unset($fields[1]);
            unset($fields[2]);
        } else {
            unset($fields[3]);
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->convertkitInstance->title)
            ->set_integration_contact_fields($this->convertkitInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    public function get_list_fields_default_data()
    {
        return [
            'custom_fields' => [
                'mappable_data'       => [
                    'first_name'
                ],
                'mappable_data_types' => [
                    'text'
                ],
                'field_values'        => [
                    'fusewpFirstName'
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

                    $data = $mappingUserDataEntity->get($mappable_data[$index]);

                    if ( ! empty($mappable_data[$index])) {

                        if ($field_value == 'fusewpFirstName') {
                            $output['firstName'] = $data;
                            continue;
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        $output['fields'][$field_value] = $data;
                    }
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

            if ($is_email_change) {
                $is_contact_exist = $this->is_contact_exist($old_email_address);
            } else {
                $is_contact_exist = $this->is_contact_exist($email_address);
            }

            $parameters = [
                'email' => $email_address,
                'tags'  => $tags
            ];

            $field_mapping = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $parameters['first_name'] = $field_mapping['firstName'];
            $parameters['fields']     = $field_mapping['fields'];

            $sequences = $GLOBALS['fusewp_sync_destination'][$list_id]['sequences'];

            if (is_array($sequences)) {
                $parameters['courses'] = array_map('intval', $sequences);
            }

            if ($is_contact_exist) {
                unset($parameters['email']);
                unset($parameters['tags']);
                unset($parameters['courses']);
            }

            if ($is_contact_exist && $is_email_change) {
                $parameters['email_address'] = $email_address;
            }

            $parameters = apply_filters(
                'fusewp_convertkit_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            if ($is_contact_exist) {

                $response = $this->convertkitInstance->apiClass()->make_request("subscribers/$is_contact_exist", $parameters, 'put');
                $this->add_tags_to_subscribers($tags, $email_address);

            } else {
                $response = $this->convertkitInstance->apiClass()->make_request("forms/{$list_id}/subscribe", $parameters, 'post');
            }

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            fusewp_log_error($this->convertkitInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        if ( ! isset($GLOBALS['fusewp_sync_destination'][$list_id]['tags'])) return;

        $tags = $GLOBALS['fusewp_sync_destination'][$list_id]['tags'];

        if (is_array($tags) && ! empty($tags)) {

            foreach ($tags as $tag_id) {
                $this->remove_contact_tag($tag_id, $email_address);
            }
        }

        return true;

        // not unsubscribing because ConvertKit unsubscribe api (https://developers.convertkit.com/#unsubscribe-subscriber) makes subscriber inactive and
        // thus, can't be resubscribed nor added to another form/list
        // so we are removing and adding tags instead of using list for segmentation.
    }

    public function remove_contact_tag($tag_id, $email_address)
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        try {

            $response = $this->convertkitInstance->apiClass()->make_request("tags/$tag_id/unsubscribe", ['email' => $email_address], 'post');

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {
            fusewp_log_error($this->convertkitInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);
        }

        return false;
    }

    public function add_tags_to_subscribers($tags, $email_address)
    {
        if ( ! is_array($tags) || empty($tags)) return;

        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        $first_tag = $tags[0];

        try {

            $this->convertkitInstance->apiClass()->make_request("tags/$first_tag/subscribe", [
                'email' => $email_address,
                'tags'  => $tags
            ], 'post');

        } catch (\Exception $e) {
            fusewp_log_error($this->convertkitInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);
        }
    }

    public function is_contact_exist($email_address)
    {
        try {

            $response = $this->convertkitInstance->apiClass()->make_request("subscribers", ['email_address' => $email_address]);

            if (isset($response['body']->subscribers[0]->id)) {
                return $response['body']->subscribers[0]->id;
            }

        } catch (\Exception $e) {
        }

        return false;
    }
}