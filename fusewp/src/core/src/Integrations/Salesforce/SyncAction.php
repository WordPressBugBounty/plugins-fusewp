<?php

namespace FuseWP\Core\Integrations\Salesforce;

use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $salesforceInstance;

    /**
     * @param Salesforce $salesforceInstance
     */
    public function __construct($salesforceInstance)
    {
        $this->salesforceInstance = $salesforceInstance;
    }

    /**
     * @return mixed
     */
    public function get_integration_id()
    {
        return $this->salesforceInstance->id;
    }

    /**
     * @param $index
     *
     * @return array
     */
    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        return [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Object', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->salesforceInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix(self::TAGS_FIELD_ID), esc_html__('Topics', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_is_multiple()
                ->set_options($this->salesforceInstance->get_topics())
                ->set_description(
                    sprintf(
                        esc_html__('Select the topics to assign to contacts. %sLearn more about topics%s', 'fusewp'),
                        '<a href="https://fusewp.com/article/crm-specific-information-user-sync/#salesforce" target="_blank">', '</a>'
                    )
                ),
        ];
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->salesforceInstance->title)
            ->set_integration_contact_fields($this->salesforceInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    /**
     * @return array
     */
    public function get_list_fields_default_data()
    {
        return [
            'custom_fields' => [
                'mappable_data'       => [
                    'user_email',
                    'first_name',
                    'last_name'
                ],
                'mappable_data_types' => [
                    'text',
                    'text',
                    'text',
                ],
                'field_values'        => [
                    'Email',
                    'FirstName',
                    'LastName'
                ]
            ]
        ];
    }

    /**
     * @param $custom_fields
     * @param MappingUserDataEntity $mappingUserDataEntity
     *
     * @return array
     */
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

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($field_type == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::DATETIME_FIELD && ! empty($data)) {
                            $data = gmdate('c', fusewp_strtotime_utc($data));
                        }

                        if (is_array($data)) {
                            $data = implode(', ', $data);
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

        $tags = $GLOBALS['fusewp_sync_destination'][$list_id]['tags'];

        try {

            $properties = ['Email' => $email_address];

            $properties = array_merge(
                $properties,
                array_filter(
                    $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                    'fusewp_is_valid_data'
                )
            );

            $properties = apply_filters(
                'fusewp_salesforce_subscription_parameters',
                array_filter($properties, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            $is_email_change = ! empty($old_email_address) && $email_address != $old_email_address;

            $existing_user_id = $this->find_userId_by_email($is_email_change ? $old_email_address : $email_address, $list_id);

            if ($existing_user_id) {

                $this->salesforceInstance->makeRequest(
                    'sobjects/' . $list_id . '/' . $existing_user_id,
                    'PATCH',
                    $properties,
                    ['Content-Type' => 'application/json']
                );

                $this->add_topics_from_entity($tags, $existing_user_id);

                return true;
            }

            // https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/dome_sobject_create.htm
            $response = $this->salesforceInstance->makeRequest(
                'sobjects/' . $list_id,
                'POST',
                $properties,
                ['Content-Type' => 'application/json']
            );

            if ( ! empty($response->id)) {
                $this->add_topics_from_entity($tags, $response->id);

                return true;
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->salesforceInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);
        }

        return false;
    }

    /**
     * @param $email_address
     * @param $list_id
     *
     * @return int|bool
     */
    public function find_userId_by_email($email_address, $list_id)
    {
        $transient_key = 'fusewp_salesforce_user_id_' . md5($email_address . $list_id);

        $cached_id = get_transient($transient_key);

        if ($cached_id !== false) return $cached_id;

        try {
            // Use SOQL query to find user by email
            $query = sprintf("SELECT Id FROM %s WHERE Email='%s'", esc_sql($list_id), esc_sql($email_address));

            $result = $this->salesforceInstance->makeRequest('query?q=' . urlencode($query));

            if ( ! empty($result->records) && isset($result->records[0]->Id)) {

                $user_id = $result->records[0]->Id;

                set_transient($transient_key, $user_id, HOUR_IN_SECONDS);

                return $user_id;
            }
        } catch (\Exception $e) {
            fusewp_log_error($this->salesforceInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return false;
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

        if ( ! isset($GLOBALS['fusewp_sync_destination'][$list_id]['tags'])) return false;

        $tags = $GLOBALS['fusewp_sync_destination'][$list_id]['tags'];

        try {

            return $this->remove_topics_from_entity(
                $tags,
                $this->find_userId_by_email($email_address, $list_id)
            );

        } catch (\Exception $e) {

            fusewp_log_error($this->salesforceInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    public function add_topics_from_entity($topic_ids, $user_id)
    {
        if (is_array($topic_ids) && ! empty($topic_ids)) {

            try {

                foreach ($topic_ids as $topic_id) {

                    $this->salesforceInstance->makeRequest(
                        'sobjects/TopicAssignment',
                        'POST',
                        [
                            'EntityId' => $user_id,
                            'TopicId'  => $topic_id
                        ],
                        ['Content-Type' => 'application/json']
                    );
                }

            } catch (\Exception $e) {
            }
        }
    }

    /**
     * @param $topic_ids
     * @param $user_id
     *
     * @return bool
     * @throws \Exception
     */
    public function remove_topics_from_entity($topic_ids, $user_id)
    {
        $success = false;

        if ( ! empty($topic_ids) && $user_id) {

            $topics_string = "'" . implode("','", $topic_ids) . "'";

            // Find TopicAssignment IDs for this contact and the specified topics
            $query = urlencode("SELECT Id, TopicId FROM TopicAssignment WHERE EntityId = '" . $user_id . "' AND TopicId IN (" . $topics_string . ")");

            $response = $this->salesforceInstance->makeRequest('query?q=' . $query);

            if ( ! empty($response->records)) {

                // Delete each matching TopicAssignment record
                foreach ($response->records as $record) {

                    try {
                        $this->salesforceInstance->makeRequest('sobjects/TopicAssignment/' . $record->Id, 'DELETE');
                        $success = true;
                    } catch (\Exception $e) {
                        $success = false;
                    }
                }
            }
        }

        return $success;
    }
}
