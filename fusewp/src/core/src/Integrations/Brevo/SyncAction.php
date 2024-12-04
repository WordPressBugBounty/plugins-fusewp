<?php

namespace FuseWP\Core\Integrations\Brevo;

use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $brevoInstance;

    /**
     * @param Brevo $brevoInstance
     */
    public function __construct($brevoInstance)
    {
        $this->brevoInstance = $brevoInstance;
    }

    public function get_integration_id()
    {
        return $this->brevoInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        return [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Contact List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->brevoInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;')
        ];
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->brevoInstance->title)
            ->set_integration_contact_fields($this->brevoInstance->get_contact_fields($list_id))
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
                    'FIRSTNAME',
                    'LASTNAME'
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

                        $field_type = fusewpVar($mappable_data_types, $index);

                        if ($field_type == ContactFieldEntity::DATE_FIELD  && ! empty($data)) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::BOOLEAN_FIELD) {
                            $data = filter_var($data, FILTER_VALIDATE_BOOLEAN);
                        }

                        if (is_array($data)) $data = implode(', ', $data);

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

            $parameters = [
                'email'         => $email_address,
                'updateEnabled' => true // ensures non doi sync can update contact if exist
            ];

            $parameters['attributes'] = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $double_optin_template        = fusewp_get_settings('brevo_double_optin_template');
            $double_optin_redirection_url = fusewp_get_settings('brevo_double_optin_redirection_url');
            $is_double_optin              = ! empty($double_optin_template) && ! empty($double_optin_redirection_url);

            $parameters = apply_filters(
                'fusewp_brevo_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            if ( ! empty($old_email_address) && $email_address != $old_email_address) {

                unset($parameters['email']);
                unset($parameters['updateEnabled']);
                // https://developers.brevo.com/reference/updatecontact
                $parameters['attributes']['EMAIL'] = $email_address;
                $response                          = $this->brevoInstance->apiClass()->make_request("contacts/$old_email_address", $parameters, 'put');

                if (fusewp_is_http_code_success($response['status'])) {
                    $this->add_contact_to_list(intval($list_id), $email_address);

                    return true;
                }
            }

            if ($is_double_optin) {

                unset($parameters['updateEnabled']);

                if ($this->is_contact_exist($email_address)) {
                    // https://developers.brevo.com/reference/updatecontact
                    $response = $this->brevoInstance->apiClass()->make_request("contacts/" . urlencode($email_address), $parameters, 'put');

                } else {

                    $parameters['includeListIds'] = [intval($list_id)];
                    $parameters['templateId']     = intval($double_optin_template);
                    $parameters['redirectionUrl'] = $double_optin_redirection_url;

                    $response = $this->brevoInstance->apiClass()->make_request('contacts/doubleOptinConfirmation', $parameters, 'post');
                }

            } else {

                $response = $this->brevoInstance->apiClass()->make_request('contacts', $parameters, 'post');
            }

            if (fusewp_is_http_code_success($response['status'])) {
                $this->add_contact_to_list(intval($list_id), $email_address);

                return true;
            }

            throw new \Exception(is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {
            fusewp_log_error($this->brevoInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $response = $this->brevoInstance->apiClass()->make_request(
                "contacts/lists/$list_id/contacts/remove",
                ['emails' => [$email_address]],
                'post'
            );

            return fusewp_is_http_code_success($response['status']);

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param $list_id
     * @param $email_address
     *
     * @return bool
     */
    protected function add_contact_to_list($list_id, $email_address)
    {
        if (empty($list_id) || empty($email_address)) return false;

        try {

            $response = $this->brevoInstance->apiClass()->make_request(
                "contacts/lists/$list_id/contacts/add",
                ['emails' => [$email_address]],
                'post'
            );

            return fusewp_is_http_code_success($response['status']);

        } catch (\Exception $e) {
            return false;
        }
    }

    protected function is_contact_exist($email_address)
    {
        try {

            $response = $this->brevoInstance->apiClass()->make_request("contacts/$email_address");

            return isset($response['body']->id);

        } catch (\Exception $e) {
        }

        return false;
    }
}