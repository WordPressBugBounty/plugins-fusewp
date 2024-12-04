<?php

namespace FuseWP\Core\Integrations\ConstantContact;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $constantcontactInstance;

    /**
     * @param ConstantContact $constantcontactInstance
     */
    public function __construct($constantcontactInstance)
    {
        $this->constantcontactInstance = $constantcontactInstance;
    }

    public function get_integration_id()
    {
        return $this->constantcontactInstance->id;
    }

    /**
     * @return array
     */
    public function get_tags()
    {
        $tags = get_transient('fusewp_constant_contact_tags');

        if ($tags === false) {

            $tags = [];

            try {

                $response = $this->constantcontactInstance->apiClass()->getTags();

                if ( ! empty($response)) {
                    foreach ($response as $tag) {
                        $tags[$tag->tag_id] = $tag->name;
                    }
                }

            } catch (\Exception $e) {
                fusewp_log_error($this->constantcontactInstance->id, __METHOD__ . ':' . $e->getMessage());
            }

            set_transient('fusewp_constant_contact_tags', $tags, 10 * MINUTE_IN_SECONDS);
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
                ->set_options($this->constantcontactInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_is_multiple()
                ->set_options($this->get_tags())
                ->set_description(esc_html__('Select the tags to assign to contacts.', 'fusewp')),
            (new Custom($prefix('constant_contact_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact and map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=constant_contact_sync_destination_upsell" target="_blank">', '</a>'
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
            ->set_integration_name($this->constantcontactInstance->title)
            ->set_integration_contact_fields($this->constantcontactInstance->get_contact_fields($list_id))
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
                    'first_name',
                    'last_name'
                ]
            ]
        ];
    }

    protected function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
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

                $contact_id = $this->get_contact_id($old_email_address, $list_id);

                if ( ! empty($contact_id)) {
                    $this->update_contact_email_address($contact_id, $email_address);
                }
            }

            $custom_field_mappings = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity);

            $properties = [
                'email_address'    => $email_address,
                'list_memberships' => [$list_id]
            ];

            if ( ! empty($custom_field_mappings)) {

                foreach ($custom_field_mappings as $ISKey => $customFieldValue) {

                    // home address transformer
                    if (false !== strpos($ISKey, 'mohma')) {
                        $fieldID                                = str_replace('mohma_', '', $ISKey);
                        $properties['street_address']['kind']   = 'home';
                        $properties['street_address'][$fieldID] = $customFieldValue;
                        continue;
                    }

                    // work address transformer
                    if (false !== strpos($ISKey, 'mowka')) {
                        $fieldID                                = str_replace('mowka_', '', $ISKey);
                        $properties['street_address']['kind']   = 'work';
                        $properties['street_address'][$fieldID] = $customFieldValue;
                        continue;
                    }

                    // other address transformer
                    if (false !== strpos($ISKey, 'moota')) {
                        $fieldID                                = str_replace('moota_', '', $ISKey);
                        $properties['street_address']['kind']   = 'other';
                        $properties['street_address'][$fieldID] = $customFieldValue;
                        continue;
                    }

                    if (false !== strpos($ISKey, 'cufd_')) {
                        $fieldID = str_replace('cufd_', '', $ISKey);

                        $properties['custom_fields'][] = ['custom_field_id' => $fieldID, 'value' => $customFieldValue];
                        continue;
                    }

                    $properties[$ISKey] = $customFieldValue;
                }
            }

            $properties = apply_filters('fusewp_constantcontact_subscription_parameters', array_filter($properties, 'fusewp_is_valid_data'), $this);

            $response = $this->constantcontactInstance->apiClass()->createOrUpdateContact($properties);

            if (isset($response->contact_id)) {

                $this->add_contact_tag($response->contact_id, $tags);

                return true;
            }

            throw new \Exception(__METHOD__ . ':' . is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {
            fusewp_log_error($this->constantcontactInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

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

            $contact_id = $this->get_contact_id($email_address, $list_id);

            if ( ! $contact_id) return false;

            $parameters = apply_filters('fusewp_constantcontact_unsubscription_parameters', [
                'source'   => ['contact_ids' => $contact_id],
                'list_ids' => [$list_id]
            ],
                $this, $list_id, $email_address
            );

            $apiClass = $this->constantcontactInstance->apiClass();

            $apiClass->apiRequest(
                'activities/remove_list_memberships',
                'POST',
                $parameters,
                ['Content-Type' => 'application/json']
            );

            return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());

        } catch (\Exception $e) {
            return false;
        }
    }

    /**@param string $contact_id
     * @param string[] $tags
     *
     * @return bool
     */
    protected function add_contact_tag($contact_id, $tags)
    {
        if (empty($contact_id) || empty($tags)) return false;

        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        try {

            $parameters = apply_filters('fusewp_constantcontact_add_contact_tag_parameters', [
                'source'  => [
                    'contact_ids' => [$contact_id]
                ],
                'tag_ids' => $tags
            ], $this, $contact_id, $tags);

            $apiClass = $this->constantcontactInstance->apiClass();

            $apiClass->apiRequest(
                'activities/contacts_taggings_add',
                'POST',
                $parameters,
                ['Content-Type' => 'application/json']
            );

            return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());

        } catch (\Exception $e) {

            fusewp_log_error($this->constantcontactInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @throws \Exception
     */
    protected function get_contact_id($email_address, $list_id)
    {
        $parameters = apply_filters(
            'fusewp_constantcontact_get_contact_id_parameters', [
            'email' => $email_address,
            'lists' => $list_id
        ], $this, $email_address);

        $contacts = $this->constantcontactInstance->apiClass()->apiRequest(
            'contacts',
            'GET',
            $parameters,
            ['Content-Type' => 'application/json']
        );

        return $contacts->contacts[0]->contact_id ?? false;
    }

    /**
     * @throws \Exception
     */
    protected function update_contact_email_address($contact_id, $new_email_address)
    {
        $parameters = apply_filters('fusewp_constantcontact_update_contact_email_address_parameters', [
            'email_address' => [
                'address' => $new_email_address
            ],
            'update_source' => 'Contact'
        ], $this, $contact_id, $new_email_address);

        $apiClass = $this->constantcontactInstance->apiClass();

        $apiClass->apiRequest(
            'contacts/' . $contact_id,
            'PUT',
            $parameters,
            ['Content-Type' => 'application/json']
        );

        return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());
    }
}