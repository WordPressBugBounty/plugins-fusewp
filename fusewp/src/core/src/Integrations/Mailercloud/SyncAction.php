<?php

namespace FuseWP\Core\Integrations\Mailercloud;

use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $mailercloudInstance;

    /**
     * @param Mailercloud $mailercloudInstance
     */
    public function __construct($mailercloudInstance)
    {
        $this->mailercloudInstance = $mailercloudInstance;
    }

    public function get_integration_id()
    {
        return $this->mailercloudInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        return [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->mailercloudInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_options($this->get_tags())
                ->set_is_multiple()
                ->set_placeholder('&mdash;&mdash;&mdash;')
        ];
    }

    protected function get_tags()
    {
        $tags_array = [];

        try {

            $page   = 1;
            $limit  = 100;
            $status = true;

            while (true === $status) {

                $response = $this->mailercloudInstance->apiClass()->post('tags/search', [
                    'limit'  => $limit,
                    'page'   => $page,
                    'search' => ''
                ]);

                if (isset($response['body']->data) && is_array($response['body']->data)) {
                    foreach ($response['body']->data as $tag) {
                        $tags_array[$tag->tag_name] = $tag->tag_name;
                    }

                    if (count($response['body']->data) < $limit) {
                        $status = false;
                    }

                    $page++;
                } else {
                    $status = false;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->mailercloudInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $tags_array;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->mailercloudInstance->title)
            ->set_integration_contact_fields($this->mailercloudInstance->get_contact_fields($list_id))
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
                    'name',
                    'last_name'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = ['custom_fields' => []];

        $default_fields = [
            'first_name',
            'last_name',
            'name',
            'phone',
            'city',
            'state',
            'country',
            'postal_code',
            'middle_name',
            'company_name',
            'job_title',
            'department',
            'industry',
            'salary',
            'lead_source',
            'userip',
            'mailbox_provider'
        ];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        $field_type = fusewpVar($mappable_data_types, $index);

                        if ($field_type == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::NUMBER_FIELD) {
                            $data = absint($data);
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        if (in_array($field_value, $default_fields)) {
                            $output[$field_value] = $data;
                        } else {
                            $output['custom_fields'][$field_value] = $data;
                        }
                    }
                }
            }
        }

        if (empty($output['custom_fields'])) {
            unset($output['custom_fields']);
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

            $parameters = [
                'email'   => $email_address,
                'list_id' => $list_id
            ];

            if ( ! empty($tags)) {
                $parameters['tags'] = (array)$tags;
            }

            $parameters = array_merge(
                $parameters,
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity)
            );

            $parameters = apply_filters(
                'fusewp_mailercloud_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            $response = $this->mailercloudInstance->apiClass()->post('contacts/upsert', $parameters);

            if (isset($response['body']->contact_id)) return true;

            throw new \Exception(is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {
            fusewp_log_error($this->mailercloudInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $tags = $GLOBALS['fusewp_sync_destination'][$list_id]['tags'] ?? '';

            $this->mailercloudInstance->apiClass()->post('contact/tag/remove', [
                'id'        => $email_address,
                'tag_names' => (array)$tags
            ]);

            return true;

        } catch (\Exception $e) {
            fusewp_log_error($this->mailercloudInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return false;
    }
}