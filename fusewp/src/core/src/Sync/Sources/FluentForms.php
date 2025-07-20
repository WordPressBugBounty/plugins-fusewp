<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;

class FluentForms extends AbstractSyncSource
{
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Fluent Forms';

        $this->id = 'fluentforms';

        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 10, 3);
        add_filter('fusewp_sync_integration_list_fields_default_data', [$this, 'add_email_default_esp_fields_mapping'], 10, 4);
        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui'], 10, 3);

        // Hook into Fluent Forms submission
        add_action('fluentform/submission_inserted', [$this, 'handle_form_submission'], 10, 3);
    }

    /**
     * @return array
     */
    public function get_source_items()
    {
        $options = [];

        $fluent_forms = wpFluent()
            ->table('fluentform_forms')
            ->select(['id', 'title'])
            ->where('status', 'published')
            ->get();

        foreach ($fluent_forms as $form) {
            $options[$form->id] = $form->title;
        }

        return $options;
    }

    /**
     * @return array
     */
    public function get_destination_items()
    {
        return [
            'form_submission' => esc_html__('After Form Submission', 'fusewp')
        ];
    }

    /**
     * @return string
     */
    public function get_destination_item_label()
    {
        return esc_html__('Event', 'fusewp');
    }

    public function get_rule_information()
    {
        return '<p>' . sprintf(
                esc_html__('Sync Fluent Forms submissions to your email marketing software after form submission. %sLearn more%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-fluent-forms-email-marketing/">', '</a>'
            ) . '</p>';
    }

    public function get_form_fields($fields)
    {
        $bucket = [];

        $metaFields = [
            'entry_id'   => esc_html__('Entry ID', 'fusewp'),
            'form_id'    => esc_html__('Form ID', 'fusewp'),
            'source_url' => esc_html__('Source URL', 'fusewp'),
            'ip'         => esc_html__('User IP Address', 'fusewp'),
            'browser'    => esc_html__('Browser', 'fusewp'),
            'user_id'    => esc_html__('User ID', 'fusewp'),
            'created_at' => esc_html__('Created At', 'fusewp'),
        ];

        $sourceData = $this->get_source_data();

        $source      = $sourceData[0];
        $source_item = $sourceData[1];

        if ($source == $this->id) {

            $form = wpFluent()
                ->table('fluentform_forms')
                ->select(['form_fields'])
                ->where('status', 'published')
                ->where('id', $source_item)
                ->first();

            $field_prefix = 'fsfluentforms_';

            if ($form && ! empty($form->form_fields)) {
                $form_fields = json_decode($form->form_fields, true);

                if (is_array($form_fields) && isset($form_fields['fields'])) {

                    foreach ($form_fields['fields'] as $field) {

                        if ( ! isset($field['attributes']['name'])) continue;

                        $field_name  = $field['attributes']['name'];
                        $field_label = $field['settings']['label'] ?? $field_name;
                        $field_type  = $field['element'] ?? 'text';

                        switch ($field_type) {
                            case 'input_name':
                                // Name field components
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_full']        = $field_label . ' (Full Name)';
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_first_name']  = $field_label . ' (First Name)';
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_middle_name'] = $field_label . ' (Middle Name)';
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_last_name']   = $field_label . ' (Last Name)';
                                break;

                            case 'address':
                                // Address field components
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_address_line_1'] = $field_label . ' (Address Line 1)';
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_address_line_2'] = $field_label . ' (Address Line 2)';
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_city']           = $field_label . ' (City)';
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_state']          = $field_label . ' (State)';
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_zip']            = $field_label . ' (ZIP Code)';
                                $bucket['Fluent Forms'][$field_prefix . $field_name . '_country']        = $field_label . ' (Country)';
                                break;

                            default:
                                // Standard fields
                                $bucket['Fluent Forms'][$field_prefix . $field_name] = $field_label;
                                break;
                        }
                    }
                }
            }

            foreach ($metaFields as $key => $label) {
                $bucket['Fluent Forms'][$field_prefix . $key] = $label;
            }

            $userDataBucketId = esc_html__('WordPress User Data', 'fusewp');

            $bucket[$userDataBucketId] = $fields[$userDataBucketId];

            return apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
        }

        return $fields;
    }

    /**
     * @param $entryId
     * @param $formData
     * @param $form
     *
     * @return void
     */
    public function handle_form_submission($entryId, $formData, $form)
    {
        try {
            $submission = wpFluent()
                ->table('fluentform_submissions')
                ->where('id', $entryId)
                ->first();

            if ( ! $submission) {
                return;
            }

            $response_data = json_decode($submission->response, true);

            if ( ! $response_data) return;

            $entry_data = array_merge($response_data, [
                'entry_id'   => $entryId,
                'form_id'    => $submission->form_id,
                'source_url' => $submission->source_url,
                'ip'         => $submission->ip,
                'browser'    => $submission->browser,
                'user_id'    => $submission->user_id,
                'created_at' => $submission->created_at,
            ]);

            $this->sync_user('form_submission', $entry_data);

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }
    }

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fsfluentforms_')) {

            $hashmap = [
                'entry_id'   => $extras['entry_id'] ?? '',
                'form_id'    => $extras['form_id'] ?? '',
                'created_at' => $extras['created_at'] ?? '',
            ];

            $field_key = str_replace('fsfluentforms_', '', $field_id);

            if (isset($hashmap[$field_key])) {
                $value = $hashmap[$field_key];
            } else {
                // Define field mappings for nested and flat structures
                $field_mappings = [
                    '_full'           => ['first_name', 'last_name'], // Special case for full name
                    '_first_name'     => 'first_name',
                    '_last_name'      => 'last_name',
                    '_middle_name'    => 'middle_name',
                    '_address_line_1' => 'address_line_1',
                    '_address_line_2' => 'address_line_2',
                    '_city'           => 'city',
                    '_state'          => 'state',
                    '_zip'            => 'zip',
                    '_country'        => 'country'
                ];

                $value = '';

                // Check if field_key matches any of the special patterns
                foreach ($field_mappings as $suffix => $nested_key) {

                    if (strpos($field_key, $suffix) !== false) {

                        $base_key = str_replace($suffix, '', $field_key);

                        if ($suffix === '_full') {
                            // Special handling for full name
                            if (isset($extras[$base_key]) && is_array($extras[$base_key])) {
                                $first_name = $extras[$base_key]['first_name'] ?? '';
                                $last_name  = $extras[$base_key]['last_name'] ?? '';
                                $value      = trim($first_name . ' ' . $last_name);
                            } else {
                                $first_name = $extras[$base_key . '_first_name'] ?? '';
                                $last_name  = $extras[$base_key . '_last_name'] ?? '';
                                $value      = trim($first_name . ' ' . $last_name);
                            }
                        } else {
                            // Handle nested structure
                            if (isset($extras[$base_key]) && is_array($extras[$base_key])) {
                                $value = $extras[$base_key][$nested_key] ?? '';
                            } else {
                                // Fallback to flat structure
                                $value = $extras[$base_key . $suffix] ?? '';
                            }
                        }
                        break;
                    }
                }

                // If no special pattern matched, use the field_key directly
                if ($value === '') {
                    $value = $extras[$field_key] ?? '';
                }
            }
        }

        return apply_filters('fusewp_' . $this->id . '_custom_field_data', $value, $field_id, $wp_user_id, $this, $extras);
    }

    protected function get_email_field_id($mapped_custom_fields)
    {
        $mappable_data = $mapped_custom_fields['mappable_data'] ?? [];
        $field_values  = $mapped_custom_fields['field_values'] ?? [];

        $email_array_key = array_search('fusewpEmail', $field_values);

        if (false !== $email_array_key && isset($mappable_data[$email_array_key])) {
            return str_replace('fsfluentforms_', '', $mappable_data[$email_array_key]);
        }

        return false;
    }

    /**
     * @param $fields
     * @param $source_id
     *
     * @return array
     */
    public function add_email_default_esp_fields_mapping($fields, $source_id)
    {
        if ($source_id == $this->id) {
            $fields['custom_fields']['mappable_data']       = ['', '', ''];
            $fields['custom_fields']['mappable_data_types'] = array_merge(['text'], $fields['custom_fields']['mappable_data_types']);
            $fields['custom_fields']['field_values']        = array_merge(['fusewpEmail'], $fields['custom_fields']['field_values']);
        }

        return $fields;
    }

    protected function is_email_field_found($integration_contact_fields)
    {
        if (is_array($integration_contact_fields)) {
            foreach ($integration_contact_fields as $contact_field) {
                if ($contact_field->id == 'fusewpEmail') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param ContactFieldEntity[] $integration_contact_fields
     *
     * @return ContactFieldEntity[]
     */
    public function add_email_field_mapping_ui($integration_contact_fields)
    {
        $sourceData = $this->get_source_data();

        if ($sourceData[0] == $this->id) {

            if ($this->is_email_field_found($integration_contact_fields) === false) {

                $field = (new ContactFieldEntity())
                    ->set_id('fusewpEmail')
                    ->set_name(esc_html__('Lead Email Address', 'fusewp'));

                array_unshift($integration_contact_fields, $field);
            }
        }

        return $integration_contact_fields;
    }

    public function sync_user($event, $entry)
    {
        $form_id = $entry['form_id'] ?? false;

        if ( ! $form_id) return;

        $user_data = $this->get_mapping_user_data($entry['user_id'] ?? 0, $entry);

        $rule = fusewp_sync_get_rule_by_source(sprintf('%s|%s', $this->id, $form_id));

        $destinations = fusewpVar($rule, 'destinations', [], true);

        if ( ! empty($destinations) && is_string($destinations)) {
            $destinations = json_decode($destinations, true);
        }

        if (is_array($destinations) && ! empty($destinations)) {

            foreach ($destinations as $destination) {

                if (fusewpVar($destination, 'destination_item') != $event) {
                    continue;
                }

                $integration = fusewpVar($destination, 'integration', '', true);

                if ( ! empty($integration)) {

                    $integration = fusewp_get_registered_sync_integrations($integration);

                    $sync_action = $integration->get_sync_action();

                    if ($integration instanceof IntegrationInterface) {

                        $custom_fields = fusewpVar($destination, $sync_action::CUSTOM_FIELDS_FIELD_ID, []);

                        $email_field_id = self::get_email_field_id($custom_fields);

                        if ( ! empty($email_field_id) && isset($entry[$email_field_id])) {

                            $email_address = $entry[$email_field_id];

                            $list_id = fusewpVar($destination, $sync_action::EMAIL_LIST_FIELD_ID, '');

                            QueueManager::push([
                                'action'                => 'subscribe_user',
                                'source_id'             => $this->id,
                                'rule_id'               => $rule['id'],
                                'destination'           => $destination,
                                'integration'           => $sync_action->get_integration_id(),
                                'mappingUserDataEntity' => $user_data,
                                'extras'                => $entry,
                                'list_id'               => $list_id,
                                'email_address'         => $email_address
                            ], 5, 1);
                        }
                    }
                }
            }
        }
    }

    public function get_bulk_sync_data($source_item_id, $paged, $number)
    {
        if ( ! function_exists('wpFluent')) return [];

        $number = max(1, intval($number));
        $paged  = max(1, intval($paged));

        try {

            return wpFluent()
                ->table('fluentform_submissions')
                ->orderBy('id', 'DESC')
                ->forPage($paged, $number)
                ->get();

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());

            return [];
        }
    }

    public function bulk_sync_handler($item)
    {
        if (is_object($item['r'])) {

            $response_data = json_decode($item['r']->response, true);

            if ($response_data) {

                $entry = array_merge($response_data, [
                    'entry_id'   => $item['r']->id,
                    'form_id'    => $item['r']->form_id,
                    'created_at' => $item['r']->created_at,
                    'source_url' => $item['r']->source_url,
                    'ip'         => $item['r']->ip,
                    'browser'    => $item['r']->browser,
                    'user_id'    => $item['r']->user_id,
                ]);

                $this->sync_user('form_submission', $entry);
            }
        }
    }

    /**
     * @return self
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
