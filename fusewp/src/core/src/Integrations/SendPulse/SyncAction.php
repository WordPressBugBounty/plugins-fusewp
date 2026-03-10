<?php

namespace FuseWP\Core\Integrations\SendPulse;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $sendPulseInstance;

    /**
     * @param SendPulse $sendPulseInstance
     */
    public function __construct(
        SendPulse $sendPulseInstance
    )
    {
        $this->sendPulseInstance = $sendPulseInstance;
    }

    public function get_integration_id()
    {
        return $this->sendPulseInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->sendPulseInstance->get_email_list())
                ->set_required()
                ->set_description(
                    sprintf(
                        esc_html__("Select a mailing list to add contacts to. Need a different list?, %sclick here%s to add one on SendPulse dashboard.",
                            'fusewp'),
                        '<a target="_blank" href="https://login.sendpulse.com/addressbooks">', '</a>'
                    )
                )
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix(self::TAGS_FIELD_ID), esc_html__('Select Tag', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_is_multiple()
                ->set_options($this->sendPulseInstance->get_tags_list())
                ->set_description(
                    sprintf(
                        esc_html__("Select a tag to assign to contacts. Tags must be created in SendPulse first. %sClick here%s to manage tags.",
                            'fusewp'),
                        '<a target="_blank" href="https://login.sendpulse.com/emailservice/tags/">', '</a>'
                    )
                ),
            (new Custom($prefix('sendpulse_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=sendpulse_sync_destination_upsell" target="_blank">',
                            '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
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
            ->set_integration_name($this->sendPulseInstance->title)
            ->set_integration_contact_fields($this->sendPulseInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    public function get_list_fields_default_data()
    {
        return [
            'custom_fields' => [
                'mappable_data'       => [
                    'fusewpFirstName',
                    'fusewpLastName'
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

    /**
     * Check if double opt-in is enabled for SendPulse sync
     *
     * @return bool
     */
    private function is_double_optin()
    {
        return fusewp_get_settings('sendpulse_sync_double_optin') == 'yes';
    }

    protected function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = [];

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

                        if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::DATE_FIELD) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        if ($field_value == 'fusewpFirstName') {
                            $first_name = $data;
                            continue;
                        }

                        if ($field_value == 'fusewpLastName') {
                            $last_name = $data;
                            continue;
                        }

                        $outputKey = empty($field_value) ? $mappable_data[$index] : $field_value;

                        if ( ! empty($outputKey)) {
                            $output[$outputKey] = $data;
                        }
                    }
                }
            }
        }

        $output['Name'] = self::get_full_name($first_name, $last_name);

        return $output;
    }

    /**
     * SendPulse API: Add email to mailing list with variables and tags
     *
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

        try {

            $transformed_data = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            // Build email data structure - always use variable format for consistency
            $email_data = [
                'email'     => $email_address,
                'variables' => ! empty($transformed_data) ? $transformed_data : [],
            ];

            // Build request payload
            $request_data = ['emails' => [$email_data]];

            // Add tags to the request if provided (tags can be included in add email request)
            if ( ! empty($tags) && is_array($tags)) {
                $request_data['tags'] = $tags;
            }

            // Add double opt-in parameters when enabled
            if ($this->is_double_optin()) {

                unset($request_data['tags']);

                $sender_email = fusewp_get_settings('sendpulse_sender_email');
                $message_lang = fusewp_get_settings('sendpulse_message_lang') ?: 'en';
                $template_id  = fusewp_get_settings('sendpulse_template_id');

                if (empty($sender_email)) {
                    fusewp_log_error($this->sendPulseInstance->id, __METHOD__ . ': Sender email is required for double opt-in but not configured.');

                    return false;
                }

                $request_data['confirmation'] = 'force';
                $request_data['sender_email'] = $sender_email;
                $request_data['message_lang'] = $message_lang;

                if ( ! empty($template_id)) {
                    $request_data['template_id'] = $template_id;
                }
            }

            // Add email to mailing list
            $response = $this->sendPulseInstance->apiClass()->post(
                "addressbooks/$list_id/emails",
                apply_filters('fusewp_sendpulse_subscription_parameters', $request_data, $this)
            );

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {

            fusewp_log_error($this->sendPulseInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            $response = $this->sendPulseInstance->apiClass()->delete(
                "addressbooks/{$list_id}/emails",
                ['emails' => [$email_address]],
            );

            return fusewp_is_http_code_success($response['status_code']);

        } catch (\Exception $e) {

            fusewp_log_error($this->sendPulseInstance->id, __METHOD__ . ':' . $e->getMessage());

            return false;
        }
    }
}
