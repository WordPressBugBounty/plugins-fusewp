<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;

class Forminator extends AbstractSyncSource
{
    public function __construct()
    {
        $this->id    = 'forminator';
        $this->title = 'Forminator Forms';

        parent::__construct();

        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 99);
        add_filter('fusewp_sync_integration_list_fields_default_data', [$this, 'add_email_default_esp_fields_mapping'], 10, 2);
        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui']);

        add_action('forminator_custom_form_submit_before_set_fields', [$this, 'handle_form_submission'], 10, 3);
    }

    /**
     * @return array
     */
    public function get_source_items()
    {
        $forms = [];

        if (class_exists('Forminator_API')) {

            $forminator_forms = \Forminator_API::get_forms(null, 1, 999);

            if (is_array($forminator_forms)) {

                foreach ($forminator_forms as $form) {
                    if (isset($form->id, $form->settings['formName'])) {
                        $forms[$form->id] = $form->settings['formName'];
                    }
                }
            }
        }

        return $forms;
    }

    /**
     * @return array
     */
    public function get_destination_items()
    {
        return ['form_submission' => esc_html__('After Form Submission', 'fusewp')];
    }

    /**
     * @return string
     */
    public function get_destination_item_label()
    {
        return esc_html__('Select Event', 'fusewp');
    }

    public function get_rule_information()
    {
        return '<p>' . sprintf(
                esc_html__('Sync Forminator Form submissions to your email marketing software after form submission. %sLearn more%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-forminator-email-marketing/">', '</a>'
            ) . '</p>';
    }

    public function get_form_fields($fields)
    {
        $sourceData = $this->get_source_data();

        $source      = $sourceData[0];
        $source_item = $sourceData[1];

        if ($source == $this->id) {

            $meta_fields = [
                'form_id'  => esc_html__('Form ID', 'fusewp'),
                'entry_id' => esc_html__('Entry ID', 'fusewp')
            ];

            $form_fields = wp_list_pluck(
                forminator_addon_format_form_fields(\Forminator_Base_Form_Model::get_model($source_item)),
                'field_label',
                'element_id'
            );

            // prefix $form_fields array keys with fsfmf7_
            $bucket['Forminator Forms'] = array_combine(array_map(function ($k) {
                return 'fsfmf7_' . $k;
            }, array_keys($form_fields)), array_values($form_fields));

            foreach ($meta_fields as $key => $label) {
                $bucket['Forminator Forms']["fsfmf7_" . $key] = $label;
            }

            $userDataBucketId          = esc_html__('WordPress User Data', 'fusewp');
            $bucket[$userDataBucketId] = $fields[$userDataBucketId];

            $fields = apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
        }

        return $fields;
    }

    public function handle_form_submission($entry, $form_id, $field_data_array)
    {
        $payload = ['form_id' => $form_id, 'entry_id' => $entry->id];

        foreach ($field_data_array as $field) {
            $payload[$field['name']] = $field['value'];
        }

        $this->sync_user('form_submission', $payload);
    }

    /**
     * @param $value
     * @param $field_id
     * @param $wp_user_id
     * @param $extras
     *
     * @return mixed|null
     */
    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fsfmf7_')) {

            $field_key = str_replace('fsfmf7_', '', $field_id);

            // Flatten nested arrays in extras
            $flattened_extras = $this->flatten_forminator_extras($extras);

            $value = $flattened_extras[$field_key] ?? '';
        }

        return apply_filters('fusewp_' . $this->id . '_custom_field_data', $value, $field_id, $wp_user_id, $this, $extras);
    }

    /**
     * Flattens Forminator form data
     *
     * @param array $extras The form extras to flatten
     *
     * @return array The flattened array
     */
    private function flatten_forminator_extras($extras)
    {
        $flattened = [];

        foreach ($extras as $key => $value) {
            // Simple values stay as-is
            if ( ! is_array($value)) {
                $flattened[$key] = $value;
                continue;
            }

            // Indexed arrays (checkboxes, etc.) stay as-is
            if (array_values($value) === $value) {
                $flattened[$key] = $value;
                continue;
            }

            // Process nested arrays
            foreach ($value as $subkey => $subvalue) {
                // Handle file uploads
                if ($subkey === 'file' && isset($subvalue['file_url'])) {
                    // Priority order: name > file_url > file_name array
                    $flattened[$key] = $subvalue['file_url'];
                    continue;
                }

                // Flatten other nested fields with hyphenated keys
                $flattened[$key . '-' . $subkey] = $subvalue;
            }
        }

        return $flattened;
    }

    public function add_email_default_esp_fields_mapping($fields, $source_id)
    {
        if ($source_id == $this->id) {
            $fields['custom_fields']['mappable_data']       = ['', '', ''];
            $fields['custom_fields']['mappable_data_types'] = array_merge(['text'], $fields['custom_fields']['mappable_data_types']);
            $fields['custom_fields']['field_values']        = array_merge(['fusewpEmail'], $fields['custom_fields']['field_values']);
        }

        return $fields;
    }

    public function is_email_field_found($integration_contact_fields)
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
     * @param $integration_contact_fields
     *
     * @return mixed
     */
    public function add_email_field_mapping_ui($integration_contact_fields)
    {
        $souceData = $this->get_source_data();

        if ($souceData[0] == $this->id) {

            if ($this->is_email_field_found($integration_contact_fields) === false) {

                $field = (new ContactFieldEntity())
                    ->set_id('fusewpEmail')
                    ->set_name(esc_html__('Lead Email Address', 'fusewp'));

                array_unshift($integration_contact_fields, $field);
            }
        }

        return $integration_contact_fields;
    }

    public static function get_email_field_id($mapped_custom_fields)
    {
        $mappable_data = $mapped_custom_fields['mappable_data'] ?? [];
        $field_values  = $mapped_custom_fields['field_values'] ?? [];

        $email_array_key = array_search('fusewpEmail', $field_values);

        if (false !== $email_array_key && isset($mappable_data[$email_array_key])) {
            return str_replace('fsfmf7_', '', $mappable_data[$email_array_key]);
        }

        return false;
    }

    public function sync_user($event, $payload)
    {
        $form_id = $payload['form_id'];

        $user_data = $this->get_mapping_user_data(0, $payload);

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

                        if ( ! empty($email_field_id) && isset($payload[$email_field_id])) {

                            $email_address = $payload[$email_field_id];

                            $list_id = fusewpVar($destination, $sync_action::EMAIL_LIST_FIELD_ID, '');

                            QueueManager::push([
                                'action'                => 'subscribe_user',
                                'source_id'             => $this->id,
                                'rule_id'               => $rule['id'],
                                'destination'           => $destination,
                                'integration'           => $sync_action->get_integration_id(),
                                'mappingUserDataEntity' => $user_data,
                                'extras'                => $payload,
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
        if ( ! class_exists('\Forminator_Form_Entry_Model')) return [];

        $number = max(1, intval($number));
        $paged  = max(1, intval($paged));

        $offset = ($paged - 1) * $number;

        return \Forminator_Form_Entry_Model::query_entries([
            "form_id"  => $source_item_id,
            "per_page" => $number,
            "offset"   => $offset
        ]);
    }

    public function bulk_sync_handler($item)
    {
        if (empty($item) || empty($item['r']->form_id)) return;

        $payload = [
            'entry_id' => $item['r']->entry_id,
            'form_id'  => $item['r']->form_id
        ];

        foreach ($item['r']->meta_data as $meta_key => $meta_value) {
            $payload[$meta_key] = $meta_value['value'];
        }

        $this->sync_user('form_submission', $payload);
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
