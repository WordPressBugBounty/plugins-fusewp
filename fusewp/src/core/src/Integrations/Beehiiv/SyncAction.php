<?php

namespace FuseWP\Core\Integrations\Beehiiv;

use Exception;
use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Admin\Fields\Text;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $beehiivInstance;

    /**
     * @param Beehiiv $beehiivInstance
     */
    public function __construct($beehiivInstance)
    {
        $this->beehiivInstance = $beehiivInstance;
    }

    /**
     * @return mixed
     */
    public function get_integration_id()
    {
        return $this->beehiivInstance->id;
    }

    /**
     * @param $index
     *
     * @return mixed
     * @throws Exception
     */
    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Tier', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->beehiivInstance->get_email_list())
                ->set_description(esc_html__("Select the tier to assign to contact.", 'fusewp'))
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Text($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_placeholder(esc_html__('tag1, tag2', 'fusewp'))
                ->set_description(esc_html__('Enter a comma-separated list of tags to assign to contacts.', 'fusewp')),
            (new Custom($prefix('beehiiv_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contacts and send welcome emails.',
                                'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=beehiiv_sync_destination_upsell" target="_blank">',
                            '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
            unset($fields[2]);
        } else {
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
            ->set_integration_name($this->beehiivInstance->title)
            ->set_integration_contact_fields($this->beehiivInstance->get_contact_fields($list_id))
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
                    'First Name',
                    'Last Name'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = ['custom_fields' => []];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        $field_type = fusewpVar($mappable_data_types, $index);

                        if ($field_type == ContactFieldEntity::DATE_FIELD) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::DATETIME_FIELD && ! empty($data)) {
                            $data = gmdate('Y-m-d H:i:s', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::BOOLEAN_FIELD) {
                            $data = filter_var($data, FILTER_VALIDATE_BOOLEAN);
                        }

                        if ($field_type == ContactFieldEntity::NUMBER_FIELD) {

                            if ( ! is_numeric($data)) $data = 0;
                            // number can be double or int
                            $data = is_float($data + 0) ? floatval($data) : absint($data);
                        }

                        if ($field_type == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = (array)$data;
                        }

                        if (is_array($data) && $field_type != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        $output['custom_fields'][] = [
                            'name'  => $field_value,
                            'value' => $data,
                        ];
                    }
                }
            }
        }

        return $output;
    }

    /**
     * Subscribe or update a user in Beehiiv
     *
     * @param string $list_id The tier ID to subscribe to
     * @param string $email_address The email address to subscribe
     * @param MappingUserDataEntity $mappingUserDataEntity User data object
     * @param array $custom_fields Custom fields to set
     * @param string $tags Comma-separated list of tags
     * @param string $old_email_address Previous email address (if changed)
     *
     * @return bool Success or failure
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        $sendWelcomeEmail = filter_var($GLOBALS['fusewp_sync_destination'][$list_id]['sendWelcomeEmail'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        try {

            $parameters = [
                'send_welcome_email' => $sendWelcomeEmail,
                'email'              => $email_address
            ];

            if ($list_id != 'all') {
                $parameters['tier']             = 'premium';
                $parameters['premium_tier_ids'] = [$list_id];
            }

            $transformed_data = array_filter(
                $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                'fusewp_is_valid_data'
            );

            $parameters = apply_filters(
                'fusewp_beehiiv_subscription_parameters',
                array_filter(array_merge($parameters, $transformed_data), 'fusewp_is_valid_data'),
                $this
            );

            if ($subscription = $this->get_subscription_by_email($email_address)) {

                $response = $this->beehiivInstance->apiClass()->make_request(
                    "publications/{publicationId}/subscriptions/$subscription->id",
                    $parameters,
                    'put'
                );

            } else {

                $response = $this->beehiivInstance->apiClass()->post("publications/{publicationId}/subscriptions", $parameters);
            }

            if (isset($response['body']->data->id, $response['body']->data->status) && $response['body']->data->status != 'invalid') {

                if ( ! empty($tags)) {

                    $tags = array_map('trim', explode(',', $tags));

                    $this->beehiivInstance->apiClass()->post(
                        sprintf("publications/{publicationId}/subscriptions/%s/tags", $response['body']->data->id),
                        ['tags' => $tags]
                    );
                }
            }

            return fusewp_is_http_code_success($response['status_code']);

        } catch (Exception $e) {
            fusewp_log_error($this->beehiivInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @param $email
     *
     * @return mixed|false
     */
    private function get_subscription_by_email($email)
    {
        try {

            $response = $this->beehiivInstance->apiClass()->make_request(
                "publications/{publicationId}/subscriptions",
                [
                    'email'  => $email,
                    'expand' => ['subscription_premium_tiers'],
                ]
            );

            if (isset($response['body']->data) && ! empty($response['body']->data)) {
                // Return the first subscription found with this email
                return $response['body']->data[0];
            }

        } catch (Exception $e) {
            fusewp_log_error($this->beehiivInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return false;
    }

    /**
     * @param $list_id
     * @param $email_address
     *
     * @return bool
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $subscription = $this->get_subscription_by_email($email_address);

            if (isset($subscription->subscription_premium_tiers) && is_array($subscription->subscription_premium_tiers)) {
                // Extract current tier IDs
                $current_tier_ids = [];
                foreach ($subscription->subscription_premium_tiers as $tier) {
                    $current_tier_ids[] = $tier->id;
                }

                // If the user isn't subscribed to this tier, nothing to do
                if ( ! in_array($list_id, $current_tier_ids)) return true;

                // Remove the specified tier
                $updated_tier_ids = array_values(array_diff($current_tier_ids, [$list_id]));

                // Set parameters based on remaining tiers
                $parameters = [];
                if (empty($updated_tier_ids)) {
                    $parameters['tier'] = 'free';
                } else {
                    $parameters['tier']             = 'premium';
                    $parameters['premium_tier_ids'] = $updated_tier_ids;
                }

                // Update the subscription
                $response = $this->beehiivInstance->apiClass()->make_request(
                    "publications/{publicationId}/subscriptions/" . $subscription->id,
                    $parameters,
                    'put'
                );

                return fusewp_is_http_code_success($response['status_code']);
            }

            return true;

        } catch (Exception $e) {
            fusewp_log_error($this->beehiivInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }
}
