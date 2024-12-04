<?php

namespace FuseWP\Core\Integrations\ZohoCampaigns;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $zohocampaignsInstance;

    /**
     * @param ZohoCampaigns $zohocampaignsInstance
     */
    public function __construct($zohocampaignsInstance)
    {
        $this->zohocampaignsInstance = $zohocampaignsInstance;
    }

    public function get_integration_id()
    {
        return $this->zohocampaignsInstance->id;
    }

    /**
     * @return array
     */
    public function get_tags()
    {
        $tags = [];

        try {

            $response = $this->zohocampaignsInstance->apiClass()->apiRequest('tag/getalltags?resfmt=JSON');

            if (isset($response->tags) && is_array($response->tags)) {

                foreach ($response->tags as $tag) {

                    $tag = new \ArrayObject($tag);

                    $tag_name = $tag->getIterator()->current()->tag_name;

                    $tags[$tag_name] = $tag_name;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->zohocampaignsInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $tags;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->zohocampaignsInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_is_multiple()
                ->set_options($this->get_tags())
                ->set_description(esc_html__('Select the tags to assign to contacts.', 'fusewp')),
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

        $fields[] = (new Custom($prefix('zohocampaigns_upsell'), esc_html__('Premium Features', 'fusewp')))
            ->set_content(function () {
                return '<p>' . sprintf(
                        esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact and map custom fields.', 'fusewp'),
                        '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=zohocampaigns_sync_destination_upsell" target="_blank">', '</a>'
                    ) . '</p>';
            });

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->zohocampaignsInstance->title)
            ->set_integration_contact_fields($this->zohocampaignsInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        if (fusewp_is_premium()) {
            unset($fields[0]);
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
                    'First Name',
                    'Last Name'
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

                        $zohocampaigns_field_id = $field_value;

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($field_type == ContactFieldEntity::BOOLEAN_FIELD) {
                            $data = true;
                        }

                        if ($field_type == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('m/d/Y', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = (array)$data;
                        }

                        if (is_array($data) && $field_type != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        $output[$zohocampaigns_field_id] = $data;
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

            $email_key   = 'Contact Email';
            $zoho_fields = $this->zohocampaignsInstance->zohocampaigns_all_fields();

            if (is_array($zoho_fields)) {

                foreach ($zoho_fields as $field) {
                    if ($field->FIELD_NAME == 'contact_email') {
                        $email_key = $field->DISPLAY_NAME;
                    }
                }
            }

            $properties = [
                'listkey'     => $list_id,
                'contactinfo' => [
                    $email_key => $email_address,
                ]
            ];

            $transformed_data = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity);

            $properties['contactinfo'] = wp_json_encode(array_merge($properties['contactinfo'], $transformed_data));

            $properties = apply_filters('fusewp_zohocampaigns_subscription_parameters', array_filter($properties, 'fusewp_is_valid_data'), $this);

            $response = $this->zohocampaignsInstance->apiClass()->apiRequest('json/listsubscribe?resfmt=JSON', 'POST', $properties);

            if (isset($response->status) && $response->status == 'success') {

                $this->assign_tag_to_contact($email_address, $tags);

                return true;
            }

            throw new \Exception(__METHOD__ . ':' . is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {

            fusewp_log_error($this->zohocampaignsInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

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

            $email_key   = 'Contact Email';
            $zoho_fields = $this->zohocampaignsInstance->zohocampaigns_all_fields();

            if (is_array($zoho_fields)) {

                foreach ($zoho_fields as $field) {
                    if ($field->FIELD_NAME == 'contact_email') {
                        $email_key = $field->DISPLAY_NAME;
                    }
                }
            }

            $payload = ['listkey' => $list_id, 'contactinfo' => wp_json_encode([$email_key => $email_address])];

            $response = $this->zohocampaignsInstance->apiClass()->apiRequest(
                'json/listunsubscribe?resfmt=JSON',
                'POST',
                $payload
            );

            return isset($response->status) && $response->status == 'success';

        } catch (\Exception $e) {
        }

        return false;
    }

    protected function assign_tag_to_contact($email_address, $db_tags)
    {
        if ( ! fusewp_is_premium()) return;

        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        try {

            if ( ! empty($db_tags) && is_array($db_tags)) {

                foreach ($db_tags as $tag) {

                    $payload = ['tagName' => $tag, 'lead_email' => $email_address];

                    $this->zohocampaignsInstance->apiClass()->apiRequest('tag/associate?resfmt=JSON', 'GET', $payload);
                }
            }
        } catch (\Exception $e) {
            fusewp_log_error($this->zohocampaignsInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);
        }
    }

    protected function get_contact_id($module, $email_address)
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        try {

            $contacts = $this->zohocampaignsInstance->apiClass()->apiRequest(
                $module . '/search?criteria=Email:equals:' . urlencode($email_address)
            );

            return $contacts->data[0]->id ?? false;

        } catch (\Exception $e) {
            fusewp_log_error($this->zohocampaignsInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }
}