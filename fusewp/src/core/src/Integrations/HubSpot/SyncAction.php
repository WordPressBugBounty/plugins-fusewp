<?php

namespace FuseWP\Core\Integrations\HubSpot;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $hubspotInstance;

    /**
     * @param HubSpot $hubspotInstance
     */
    public function __construct($hubspotInstance)
    {
        $this->hubspotInstance = $hubspotInstance;
    }

    public function get_integration_id()
    {
        return $this->hubspotInstance->id;
    }

    protected function get_property_options($property)
    {
        try {

            $cache_key = 'fusewp_hubspot_get_property_options_' . $property;

            $options = get_transient($cache_key);

            if (false === $options) {

                $response = $this->hubspotInstance->apiClass()->apiRequest(
                    sprintf('crm/v3/properties/contacts/%s', $property)
                );

                $options = [];

                if (isset($response->options)) {

                    $options = array_reduce($response->options, function ($carry, $item) {
                        $carry[$item->value] = $item->label;

                        return $carry;
                    }, []);

                    set_transient($cache_key, $options, DAY_IN_SECONDS);
                }
            }

            return $options;

        } catch (\Exception $e) {
            fusewp_log_error($this->hubspotInstance->id, __METHOD__ . ':' . $e->getMessage());

            return [];
        }
    }

    protected function get_owners()
    {
        $bucket = get_transient('fusewp_hubspot_get_owners');

        if (false === $bucket) {

            $bucket = ['' => '&mdash;&mdash;&mdash;'];

            try {

                $response = $this->hubspotInstance->apiClass()->apiRequest('crm/v3/owners/');

                if ( ! empty($response->results)) {

                    foreach ($response->results as $item) {
                        $bucket[$item->id] = $item->firstName . ' ' . $item->lastName;
                    }

                    set_transient('fusewp_hubspot_get_owners', $bucket, DAY_IN_SECONDS);
                }

            } catch (\Exception $e) {
                fusewp_log_error($this->hubspotInstance->id, __METHOD__ . ':' . $e->getMessage());
            }
        }

        return $bucket;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->hubspotInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix('contact_owner'), esc_html__('Contact Owner', 'fusewp')))
                ->set_db_field_id('contact_owner')
                ->set_options($this->get_owners())
                ->set_description(esc_html__('Select a HubSpot user that will be assigned as the owner of subscribed users.', 'fusewp')),
            (new Select($prefix('lifecyclestage'), esc_html__('Lifecycle Stage', 'fusewp')))
                ->set_db_field_id('lifecyclestage')
                ->set_options($this->get_property_options('lifecyclestage')),
            (new Select($prefix('hs_lead_status'), esc_html__('Lead Status', 'fusewp')))
                ->set_db_field_id('hs_lead_status')
                ->set_options($this->get_property_options('hs_lead_status')),
            (new Custom($prefix('hubspot_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to select contact owner, define Lifecycle stage & Lead status and map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=hubspot_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                })
        ];

        if ( ! fusewp_is_premium()) {
            unset($fields[1]);
            unset($fields[2]);
            unset($fields[3]);
        } else {
            unset($fields[4]);
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->hubspotInstance->title)
            ->set_integration_contact_fields($this->hubspotInstance->get_contact_fields($list_id))
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

                        $hubspot_field_id = $field_value;

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($hubspot_field_id == 'fusewpFirstName') {
                            $output['firstname'] = $data;
                            continue;
                        }

                        if ($hubspot_field_id == 'fusewpLastName') {
                            $output['lastname'] = $data;
                            continue;
                        }

                        // HS accept date in unix timestamp in milliseconds
                        // https://legacydocs.hubspot.com/docs/faq/how-should-timestamps-be-formatted-for-hubspots-apis
                        if ($field_type == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            // HS date must be set to midnight UTC for the date you want
                            $data = fusewp_strtotime_utc(gmdate('Y-m-d 00:00:00', fusewp_strtotime_utc($data))) * 1000;
                        }

                        // HS accept date in unix timestamp in milliseconds
                        if ($field_type == ContactFieldEntity::DATETIME_FIELD && ! empty($data)) {
                            $data = fusewp_strtotime_utc($data) * 1000;
                        }

                        // https://legacydocs.hubspot.com/docs/faq/how-do-i-set-multiple-values-for-checkbox-properties
                        if ($field_type == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(';', (array)$data);
                        }

                        if (is_array($data) && $field_type != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        $output[$hubspot_field_id] = $data;
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

            $properties = ['email' => $email_address];

            $lead_status      = $GLOBALS['fusewp_sync_destination'][$list_id]['hs_lead_status'];
            $life_cycle_stage = $GLOBALS['fusewp_sync_destination'][$list_id]['lifecyclestage'];
            $owner            = $GLOBALS['fusewp_sync_destination'][$list_id]['contact_owner'];

            if ( ! empty($life_cycle_stage)) {
                $properties['lifecyclestage'] = $life_cycle_stage;
            }

            if ( ! empty($lead_status)) {
                $properties['hs_lead_status'] = $lead_status;
            }

            if ( ! empty($owner)) {
                $properties['hubspot_owner_id'] = $owner;
            }

            // not using fusewp_is_valid_data so removed/empty data can be transferred over to HubSpot. That is, if a user empty out a field or
            // want to remove a previously selected data, fusewp_is_valid_data would remove that field. so we dont want that.
            $transformed_data = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity);

            $properties = apply_filters('fusewp_hubspot_subscription_parameters', array_merge($properties, $transformed_data), $this);

            $contact_data = ['properties' => []];

            foreach ($properties as $property => $value) {
                $contact_data['properties'][] = [
                    'property' => $property,
                    'value'    => $value
                ];
            }

            $response = $this->hubspotInstance->apiClass()->addSubscriber(
                $list_id,
                $is_email_change ? $old_email_address : $email_address,
                $contact_data
            );

            if (isset($response->vid)) return true;

            throw new \Exception(__METHOD__ . ':' . is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {

            fusewp_log_error($this->hubspotInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

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

            $contact_id = $this->get_contact_id($email_address);

            if ( ! $contact_id) return false;

            $parameters = apply_filters('fusewp_hubspot_unsubscription_parameters',
                [absint($contact_id)],
                $this, $list_id, $email_address
            );

            $apiClass = $this->hubspotInstance->apiClass();

            $apiClass->apiRequest(
                "crm/v3/lists/$list_id/memberships/remove",
                'PUT',
                $parameters,
                ['Content-Type' => 'application/json']
            );

            return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @throws \Exception
     */
    public function get_contact_id($email_address)
    {
        $contact_id = get_transient('fusewp_hubspot_contact_id_' . $email_address);

        if (empty($contact_id)) {

            $contact_id = false;

            $parameters = apply_filters(
                'fusewp_hubspot_get_contact_id_parameters', [
                'idProperty' => 'email',
                'inputs'     => [['id' => $email_address]],
                'properties' => ['email']
            ], $this, $email_address);

            $contacts = $this->hubspotInstance->apiClass()->apiRequest(
                'crm/v3/objects/contacts/batch/read',
                'POST',
                $parameters,
                ['Content-Type' => 'application/json']
            );

            if (isset($contacts->results[0]->id)) {

                $contact_id = $contacts->results[0]->id;

                set_transient('fusewp_hubspot_contact_id_' . $email_address, $contact_id, 12 * HOUR_IN_SECONDS);
            }
        }

        return $contact_id;
    }
}