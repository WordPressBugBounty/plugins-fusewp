<?php

namespace FuseWP\Core\Integrations\ZohoCRM;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Admin\Fields\Text;
use FuseWP\Core\Admin\Fields\Textarea;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $zohocrmInstance;

    /**
     * @param ZohoCRM $zohocrmInstance
     */
    public function __construct($zohocrmInstance)
    {
        $this->zohocrmInstance = $zohocrmInstance;
    }

    public function get_integration_id()
    {
        return $this->zohocrmInstance->id;
    }

    public function get_owners()
    {
        $cache_key = 'fusewp_zohocrm_owners';

        $owners = get_transient($cache_key);

        if ( ! empty($owners) && is_array($owners)) return $owners;

        $bucket = ['' => '&mdash;&mdash;&mdash;'];

        try {

            $response = $this->zohocrmInstance->apiClass()->apiRequest('users?type=AllUsers');

            if ( ! empty($response->users)) {

                foreach ($response->users as $user) {
                    $bucket[$user->id] = $user->full_name;
                }

                set_transient($cache_key, $bucket, HOUR_IN_SECONDS);
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->zohocrmInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

    public function get_module_sources($module)
    {
        $cache_key = 'fusewp_zohocrm_module_sources_' . $module;

        $sources = get_transient($cache_key);

        if ( ! empty($sources) && is_array($sources)) return $sources;

        $sources = [];

        try {

            $response = $this->zohocrmInstance->apiClass()->apiRequest("settings/layouts?module={$module}");

            if (isset($response->layouts[0]->sections) && is_array($response->layouts[0]->sections)) {

                foreach ($response->layouts[0]->sections as $section) {

                    foreach ($section->fields as $field) {

                        if ($field->api_name == 'Lead_Source') {
                            foreach ($field->pick_list_values as $value) {
                                $sources[$value->actual_value] = $value->display_value;
                            }

                            set_transient($cache_key, $sources, HOUR_IN_SECONDS);
                            break;
                        }
                    }
                }
            }

            return $sources;

        } catch (\Exception $e) {
            fusewp_log_error($this->zohocrmInstance->id, __METHOD__ . ':' . $e->getMessage());

            return [];
        }
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Module', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->zohocrmInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Text($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_placeholder(esc_html__('tag1, tag2', 'fusewp'))
                ->set_description(esc_html__('Enter a comma-separated list of tags to assign to contacts.', 'fusewp'))
        ];

        if ( ! fusewp_is_premium()) {
            unset($fields[1]);
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new Select($prefix('zohocrm_owner'), ($list_id == 'Leads' ? 'Lead' : 'Contact') . ' Owner'))
            ->set_db_field_id('zohocrm_owner')
            ->set_options($this->get_owners())
            ->set_description(esc_html__('Select a Zoho CRM user that will be assigned as the owner of subscribed users.', 'fusewp'));

        $fields[] = (new Select($prefix('zohocrm_source'), 'Lead Source'))
            ->set_db_field_id('zohocrm_source')
            ->set_options($this->get_module_sources($list_id));

        $fields[] = (new Textarea($prefix('zohocrm_description'), ($list_id == 'Leads' ? 'Lead' : 'Contact') . ' Description'))
            ->set_db_field_id('zohocrm_description');

        $fields[] = (new Select($prefix('zohocrm_trigger'), esc_html__('Trigger', 'fusewp')))
            ->set_db_field_id('zohocrm_trigger')
            ->set_is_multiple()
            ->set_options([
                'approval'  => 'Approval',
                'workflow'  => 'Workflow',
                'blueprint' => 'Blueprint'
            ])
            ->set_description(esc_html__('Select triggers that will be executed when a user or lead is synced.', 'fusewp'));

        $fields[] = (new Custom($prefix('zohocrm_upsell'), esc_html__('Premium Features', 'fusewp')))
            ->set_content(function () {
                return '<p>' . sprintf(
                        esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact, define contact owner, set description, lead source & triggers and map custom fields.', 'fusewp'),
                        '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=zohocrm_sync_destination_upsell" target="_blank">', '</a>'
                    ) . '</p>';
            });

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->zohocrmInstance->title)
            ->set_integration_contact_fields($this->zohocrmInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        if ( ! fusewp_is_premium()) {
            unset($fields[0]);
            unset($fields[1]);
            unset($fields[2]);
            unset($fields[3]);
        } else {
            unset($fields[4]);
        }

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
                    'First_Name',
                    'Last_Name'
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

                        $zohocrm_field_id = $field_value;

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        // https://help.zoho.com/portal/en/community/topic/php-sdk-date-format
                        if ($field_type == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::DATETIME_FIELD && ! empty($data)) {
                            $data = gmdate('c', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::NUMBER_FIELD) {
                            $data = absint($data);
                        }

                        if ($field_type == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = (array)$data;
                        }

                        if (is_array($data) && $field_type != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        $output[$zohocrm_field_id] = $data;
                    }
                }
            }
        }

        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $is_email_change = ! empty($old_email_address) && $email_address != $old_email_address;

            $owner       = $GLOBALS['fusewp_sync_destination'][$list_id]['zohocrm_owner'];
            $source      = $GLOBALS['fusewp_sync_destination'][$list_id]['zohocrm_source'];
            $description = $GLOBALS['fusewp_sync_destination'][$list_id]['zohocrm_description'];
            $triggers    = $GLOBALS['fusewp_sync_destination'][$list_id]['zohocrm_trigger'];

            $properties = [
                'Email'       => $email_address,
                'Owner'       => $owner,
                'Lead_Source' => $source,
                'Description' => $description
            ];

            $properties = array_filter($properties, 'fusewp_is_valid_data');

            $transformed_data = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $properties = apply_filters(
                'fusewp_zohocrm_subscription_parameters',
                array_filter(array_merge($properties, $transformed_data), 'fusewp_is_valid_data'),
                $this
            );

            $triggers = apply_filters('fusewp_zoho_crm_subscription_triggers', $triggers, $this);

            if (empty($triggers)) $triggers = [];

            $update_flag = false;

            if ($is_email_change) {

                $contact_id = $this->get_contact_id($list_id, $old_email_address);

                if ( ! empty($contact_id)) {

                    $update_flag = true;

                    $response = $this->zohocrmInstance->apiClass()->apiRequest(
                        sprintf('%s/%s', $list_id, $contact_id),
                        'PUT',
                        ['data' => [$properties], 'trigger' => $triggers],
                        ['Content-Type' => 'application/json']
                    );
                }
            }

            if ( ! $update_flag) {
                $response = $this->zohocrmInstance->apiClass()->apiRequest(
                    sprintf('%s/upsert', $list_id),
                    'POST',
                    ['data' => [$properties], 'trigger' => $triggers],
                    ['Content-Type' => 'application/json']
                );
            }

            if (isset($response->data[0]->details->id)) {

                $this->assign_tag_to_contact(
                    $list_id,
                    $response->data[0]->details->id,
                    $tags
                );

                return true;
            }

            throw new \Exception(__METHOD__ . ':' . is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {

            fusewp_log_error($this->zohocrmInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        $db_tags = $GLOBALS['fusewp_sync_destination'][$list_id][self::TAGS_FIELD_ID];

        $contact_id = $this->get_contact_id($list_id, $email_address);

        if ( ! empty($contact_id)) {

            try {

                $tags = [];

                if ( ! empty($db_tags)) {
                    $tags = array_map(function ($val) {
                        return ['name' => trim($val)];
                    }, explode(',', $db_tags));
                }

                if ( ! empty($tags)) {

                    $payload = ['tags' => $tags, 'ids' => [$contact_id]];

                    $apiClass = $this->zohocrmInstance->apiClass();

                    $apiClass->apiRequest(
                        sprintf('%s/actions/remove_tags', $list_id),
                        'POST',
                        $payload,
                        ['Content-Type' => 'application/json']
                    );

                    return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());
                }

            } catch (\Exception $e) {
            }
        }

        return false;
    }

    protected function assign_tag_to_contact($module, $contact_id, $db_tags)
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        try {

            $tags = [];

            if ( ! empty($db_tags)) {
                $tags = array_map(function ($val) {
                    return ['name' => trim($val)];
                }, explode(',', $db_tags));
            }

            if ( ! empty($tags)) {

                $payload = ['tags' => $tags, 'ids' => [$contact_id]];

                $this->zohocrmInstance->apiClass()->apiRequest(
                    sprintf('%s/actions/add_tags', $module),
                    'POST',
                    $payload,
                    ['Content-Type' => 'application/json']
                );
            }
        } catch (\Exception $e) {
            fusewp_log_error($this->zohocrmInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);
        }
    }

    protected function get_contact_id($module, $email_address)
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        try {

            $contacts = $this->zohocrmInstance->apiClass()->apiRequest(
                $module . '/search?criteria=Email:equals:' . urlencode($email_address)
            );

            return $contacts->data[0]->id ?? false;

        } catch (\Exception $e) {
            fusewp_log_error($this->zohocrmInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }
}