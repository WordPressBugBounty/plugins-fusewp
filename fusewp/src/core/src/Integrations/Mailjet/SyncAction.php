<?php

namespace FuseWP\Core\Integrations\Mailjet;

use Exception;
use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $mailjetInstance;

    /**
     * @param Mailjet $mailjetInstance
     */
    public function __construct($mailjetInstance)
    {
        $this->mailjetInstance = $mailjetInstance;
    }

    public function get_integration_id()
    {
        return $this->mailjetInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->mailjetInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Custom($prefix('mailjet_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=mailjet_sync_destination_upsell" target="_blank">',
                            '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
            unset($fields[1]);
        }

        return $fields;
    }

    protected function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = ['customFieldValues' => []];

        $first_name = '';
        $last_name  = '';

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        $field_type = fusewpVar($mappable_data_types, $index);

                        if ($field_type == ContactFieldEntity::BOOLEAN_FIELD) {
                            $data = filter_var($data, FILTER_VALIDATE_BOOLEAN);
                        }

                        if ($field_type == ContactFieldEntity::DATETIME_FIELD) {
                            $data = gmdate('c', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::NUMBER_FIELD && ! empty($data)) {
                            $data = absint($data);
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        if (fusewp_is_valid_data($data)) {
                            $output['Properties'][$field_value] = $data;
                        }
                    }
                }
            }
        }

        $output['Name'] = self::get_full_name($first_name, $last_name);

        return $output;
    }

    /**
     * https://api.mailjet.com/v3/REST/contactslist/{list_ID}/managecontact
     * @inheritDoc
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $parameters = [
                'Action' => 'addforce',
                'Email'  => $email_address,
            ];

            $parameters = array_merge($parameters, array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            ));

            $parameters = apply_filters(
                'fusewp_mailjet_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this
            );

            $response = $this->mailjetInstance->apiClass()->post(
                "contactslist/$list_id/managecontact",
                $parameters,
            );

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {

            fusewp_log_error($this->mailjetInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        $parameters = apply_filters('fusewp_mailjet_unsubscription_parameters', [
            // unsub -> Unsubscribe the contact from this list || remove -> Remove the contact from this list
            'Action' => 'remove',
            'Email'  => $email_address,
        ], $list_id, $email_address, $this);

        try {

            $response = $this->mailjetInstance->apiClass()->post(
                "contactslist/$list_id/managecontact",
                $parameters,
            );

            return fusewp_is_http_code_success($response['status_code']);

        } catch (Exception $e) {
            return false;
        }
    }

    public function get_list_fields_default_data()
    {
        return [
            'custom_fields' => [
                'mappable_data'       => [
                    'first_name',
                    'last_name',
                ],
                'mappable_data_types' => [
                    'text',
                    'text',
                ],
                'field_values'        => [
                    'firstname',
                    'name',
                ],
            ],
        ];
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->mailjetInstance->title)
            ->set_integration_contact_fields($this->mailjetInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }
}
