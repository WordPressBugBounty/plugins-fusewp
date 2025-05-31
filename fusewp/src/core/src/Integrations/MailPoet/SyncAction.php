<?php

namespace FuseWP\Core\Integrations\MailPoet;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;
use MailPoet\API\API;

class SyncAction extends AbstractSyncAction
{
    protected $mailpoetInstance;

    /**
     * @param MailPoet $mailpoetInstance
     */
    public function __construct($mailpoetInstance)
    {
        $this->mailpoetInstance = $mailpoetInstance;
    }

    public function get_integration_id()
    {
        return $this->mailpoetInstance->id;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->mailpoetInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Custom($prefix('mailpoet_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=mailpoet_sync_destination_upsell" target="_blank">',
                            '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
            unset($fields[1]);
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        if (fusewp_is_premium()) {
            $fields[] = (new Select($prefix('sendWelcomeEmail'), esc_html__('Send Welcome Email', 'fusewp')))
                ->set_db_field_id('sendWelcomeEmail')
                ->set_description(esc_html__('Enable to send welcome email to new subscribers.', 'fusewp'))
                ->set_options(['true' => 'Enable', 'false' => 'Disable']);
        }

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->mailpoetInstance->title)
            ->set_integration_contact_fields($this->mailpoetInstance->get_contact_fields($list_id))
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
                        $mailpoet_field_id   = $field_value;
                        $mailpoet_field_type = fusewpVar($mappable_data_types, $index);

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($mailpoet_field_type == ContactFieldEntity::DATE_FIELD) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($mailpoet_field_type == ContactFieldEntity::BOOLEAN_FIELD) {
                            $data = filter_var($data, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                        }

                        if (is_array($data)) $data = implode(', ', $data);

                        $output[$mailpoet_field_id] = $data;
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

        try {

            $mailpoet_api = API::MP('v1');

            $parameters = ['email' => $email_address];

            $parameters = apply_filters(
                'fusewp_mailpoet_subscription_parameters',
                array_filter(array_merge($parameters, $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity)), 'fusewp_is_valid_data'),
                $this
            );

            $subscriber = null;

            $sendWelcomeEmail = filter_var($GLOBALS['fusewp_sync_destination'][$list_id]['sendWelcomeEmail'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

            $options = [
                'schedule_welcome_email' => $sendWelcomeEmail
            ];

            try {
                // Check if subscriber exists. If subscriber doesn't exist an exception is thrown
                $subscriber = $mailpoet_api->getSubscriber($email_address);

            } catch (\Exception $e) {
            }

            if ( ! empty($subscriber)) {

                $mailpoet_api->updateSubscriber($subscriber['id'], $parameters);
                $mailpoet_api->subscribeToList($subscriber['id'], $list_id, $options);
            } else {
                $mailpoet_api->addSubscriber($parameters, [$list_id], $options);
            }

            return true;

        } catch (\Exception $e) {
            fusewp_log_error($this->mailpoetInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @param $list_id
     * @param $email_address
     *
     * @return bool
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        try {

            API::MP('v1')->unsubscribeFromLists($email_address, [$list_id]);

            return true;
        } catch (\Exception $e) {
            fusewp_log_error($this->mailpoetInstance->id, __METHOD__ . ':' . $e->getMessage());

            return false;
        }
    }
}
