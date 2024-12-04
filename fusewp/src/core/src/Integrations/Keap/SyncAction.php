<?php

namespace FuseWP\Core\Integrations\Keap;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $keapInstance;

    /**
     * @param Keap $keapInstance
     */
    public function __construct($keapInstance)
    {
        $this->keapInstance = $keapInstance;
    }

    public function get_integration_id()
    {
        return $this->keapInstance->id;
    }

    public function get_owners()
    {
        $cache_key = 'fusewp_keap_owners';

        $owners = get_transient($cache_key);

        if ( ! empty($owners) && is_array($owners)) return $owners;

        $bucket = ['' => '&mdash;&mdash;&mdash;'];

        try {

            $response = $this->keapInstance->apiClass()->get_users();

            if ( ! empty($response)) {

                $bucket = array_replace($bucket, $response);

                set_transient($cache_key, $bucket, HOUR_IN_SECONDS);
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->keapInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Tag', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->keapInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix('contact_type'), esc_html__('Person Type', 'fusewp')))
                ->set_db_field_id('contact_type')
                ->set_options([
                    ''                 => '&mdash;&mdash;&mdash;',
                    'Prospect'         => esc_html__('Prospect', 'fusewp'),
                    'Customer'         => esc_html__('Customer', 'fusewp'),
                    'Partner'          => esc_html__('Partner', 'fusewp'),
                    'Personal Contact' => esc_html__('Personal Contact', 'fusewp'),
                    'Vendor'           => esc_html__('Vendor', 'fusewp'),
                ]),
            (new Select($prefix('lead_source_id'), esc_html__('Lead Source', 'fusewp')))
                ->set_db_field_id('lead_source_id')
                ->set_options([
                    ''   => '&mdash;&mdash;&mdash;',
                    '6'  => esc_html__('Advertisement', 'fusewp'),
                    '9'  => esc_html__('Direct Mail', 'fusewp'),
                    '11' => esc_html__('Online - Organic Search Engine', 'fusewp'),
                    '12' => esc_html__('Online - Pay Per Click', 'fusewp'),
                    '7'  => esc_html__('Referral - From Affiliate/Partner', 'fusewp'),
                    '8'  => esc_html__('Referral - From Customer', 'fusewp'),
                    '13' => esc_html__('Trade Show', 'fusewp'),
                    '10' => esc_html__('Yellow Pages', 'fusewp'),
                ]),
            (new Select($prefix('lead_owner'), esc_html__('Owner ID', 'fusewp')))
                ->set_db_field_id('lead_owner')
                ->set_options($this->get_owners())
        ];

        if ( ! fusewp_is_premium()) {
            unset($fields[1]);
            unset($fields[2]);
            unset($fields[3]);
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new Custom($prefix('keap_upsell'), esc_html__('Premium Features', 'fusewp')))
            ->set_content(function () {
                return '<p>' . sprintf(
                        esc_html__('%sUpgrade to FuseWP Premium%s to define person type, contact owner, lead source and map custom fields.', 'fusewp'),
                        '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=keap_sync_destination_upsell" target="_blank">', '</a>'
                    ) . '</p>';
            });

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->keapInstance->title)
            ->set_integration_contact_fields($this->keapInstance->get_contact_fields($list_id))
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
                    'given_name',
                    'family_name'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity, &$output)
    {
        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $keap_field_id) {

                    if ( ! empty($mappable_data[$index])) {

                        $field_type = fusewpVar($mappable_data_types, $index);

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($field_type == ContactFieldEntity::DATE_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d\TH:i:s\Z', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::DATETIME_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d\TH:i:s\Z', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = (array)$data;
                        }

                        if (is_array($data) && $field_type != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        if ($keap_field_id == 'mocompany') {
                            $output['company'] = ['id' => absint($data)];
                            continue;
                        }

                        // email1 transformer
                        if ($keap_field_id == 'email_address_2') {
                            $output['email_addresses'][] = [
                                'email' => $data,
                                'field' => 'EMAIL2'
                            ];
                            continue;
                        }

                        if ($keap_field_id == 'email_address_3') {
                            $output['email_addresses'][] = [
                                'email' => $data,
                                'field' => 'EMAIL3'
                            ];
                            continue;
                        }

                        // phone number transformer
                        if ($keap_field_id == 'mophne_phone_1') {
                            $output['phone_numbers'][0]['field']  = 'PHONE1';
                            $output['phone_numbers'][0]['number'] = $data;
                            continue;
                        }

                        if ($keap_field_id == 'mophne_phone_1_ext') {
                            $output['phone_numbers'][0]['extension'] = $data;
                            continue;
                        }

                        if ($keap_field_id == 'mophne_phone_2') {
                            $output['phone_numbers'][1]['field']  = 'PHONE2';
                            $output['phone_numbers'][1]['number'] = $data;
                            continue;
                        }

                        if ($keap_field_id == 'mophne_phone_2_ext') {
                            $output['phone_numbers'][1]['extension'] = $data;
                            continue;
                        }

                        // billing address transformer
                        if (false !== strpos($keap_field_id, 'moblla')) {
                            $fieldID = str_replace('moblla_address_', '', $keap_field_id);
                            $fieldID = str_replace(
                                ['city', 'state', 'country'],
                                ['locality', 'region', 'country_code'],
                                $fieldID
                            );

                            // Contacts doesn't get added if region/state is lowercase.
                            if ($fieldID == 'region') $data = ucwords($data);

                            $output['addresses'][0]['field']  = 'BILLING';
                            $output['addresses'][0][$fieldID] = $data;
                            continue;
                        }

                        // shipping address transformer
                        if (false !== strpos($keap_field_id, 'moshpa')) {
                            $fieldID = str_replace('moshpa_address_', '', $keap_field_id);
                            $fieldID = str_replace(
                                ['city', 'state', 'country'],
                                ['locality', 'region', 'country_code'],
                                $fieldID
                            );

                            if ($fieldID == 'region') {
                                $data = ucwords($data);
                            }

                            $output['addresses'][1]['field']  = 'SHIPPING';
                            $output['addresses'][1][$fieldID] = $data;
                            continue;
                        }

                        // other address transformer
                        if (false !== strpos($keap_field_id, 'motha')) {
                            $fieldID = str_replace('motha_address_', '', $keap_field_id);
                            $fieldID = str_replace(
                                ['city', 'state', 'country'],
                                ['locality', 'region', 'country_code'],
                                $fieldID
                            );

                            if ($fieldID == 'region') $data = ucwords($data);

                            $output['addresses'][2]['field']  = 'OTHER';
                            $output['addresses'][2][$fieldID] = $data;
                            continue;
                        }

                        // social network transformer
                        if (false !== strpos($keap_field_id, 'mosonk')) {
                            $fieldID = str_replace('Linkedin', 'LinkedIn', ucwords(str_replace('mosonk_', '', $keap_field_id)));

                            $output['social_accounts'][] = ['name' => $data, 'type' => $fieldID];
                            continue;
                        }

                        // custom fields transformer
                        if (false !== strpos($keap_field_id, 'cufd_')) {
                            $fieldID = absint(str_replace('cufd_', '', $keap_field_id));

                            $output['custom_fields'][] = ['content' => $data, 'id' => $fieldID];
                            continue;
                        }

                        $output[$keap_field_id] = $data;
                    }
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $is_email_change = ! empty($old_email_address) && $email_address != $old_email_address;

            $person_type = $GLOBALS['fusewp_sync_destination'][$list_id]['contact_type'];
            $lead_source = $GLOBALS['fusewp_sync_destination'][$list_id]['lead_source_id'];
            $owner_id    = $GLOBALS['fusewp_sync_destination'][$list_id]['lead_owner'];

            $properties = [
                "duplicate_option" => "Email",
                "source_type"      => "WEBFORM",
                'origin'           => ['ip_address' => fusewp_get_ip_address()],
                "opt_in_reason"    => apply_filters('fusewp_keap_opt_in_reason', 'Customer opted-in through FuseWP'),
            ];

            $properties['email_addresses'][] = [
                'email' => $email_address,
                'field' => 'EMAIL1'
            ];

            if ( ! empty($person_type)) {
                $properties['contact_type'] = $person_type;
            }

            if ( ! empty($lead_source)) {
                $properties['lead_source_id'] = absint($lead_source);
            }

            if ( ! empty($owner_id)) {
                $properties['owner_id'] = absint($owner_id);
            }

            $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity, $properties);

            $properties = apply_filters('fusewp_keap_subscription_parameters', array_filter($properties, 'fusewp_is_valid_data'), $this);

            $update_flag = false;

            if ($is_email_change) {

                $contact_id = $this->get_contact_id($old_email_address);

                if ( ! empty($contact_id)) {
                    unset($properties['duplicate_option']);
                    $update_flag = true;
                    $response    = $this->keapInstance->apiClass()->apiRequest(sprintf("contacts/%s", $contact_id), 'PATCH', $properties);
                }
            }

            if (false === $update_flag) {

                $response = $this->keapInstance->apiClass()->addUpdateSubscriber($properties);
            }

            if (isset($response->id)) {

                $this->keapInstance->apiClass()->apply_tags($response->id, [$list_id]);

                return true;
            }

            throw new \Exception(__METHOD__ . ':' . is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {

            fusewp_log_error($this->keapInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        $contact_id = $this->get_contact_id($email_address);

        if ( ! empty($contact_id)) {

            try {

                $apiClass = $this->keapInstance->apiClass();

                $apiClass->apiRequest(sprintf('contacts/%s/tags/%s', $contact_id, $list_id), 'DELETE');

                return fusewp_is_http_code_success($apiClass->getHttpClient()->getResponseHttpCode());

            } catch (\Exception $e) {
            }
        }

        return false;
    }

    /**
     * @param $email_address
     *
     * @return int|false
     */
    protected function get_contact_id($email_address)
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        try {

            $contacts = $this->keapInstance->apiClass()->apiRequest('contacts', 'GET', ['email' => $email_address]);

            return $contacts->contacts[0]->id ?? false;

        } catch (\Exception $e) {
            fusewp_log_error($this->keapInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }
}