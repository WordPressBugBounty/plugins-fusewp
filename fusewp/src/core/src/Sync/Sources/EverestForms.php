<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;

class EverestForms extends AbstractSyncSource
{
    public function __construct()
    {
        $this->title = 'Everest Forms';

        $this->id = 'everestforms';

        parent::__construct();

        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 10, 3);
        add_filter('fusewp_sync_integration_list_fields_default_data', [$this, 'add_email_default_esp_fields_mapping'], 10, 4);
        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui'], 10, 3);

        add_action('everest_forms_process_complete', function ($fields, $entry, $form_data, $entry_id) {
            $this->handle_form_submission($entry_id);
        }, 10, 4);
    }

    public function get_source_items()
    {
        $options = [];

        $forms = evf_get_all_forms();

        if ( ! empty($forms)) {
            foreach ($forms as $form_id => $form_title) {
                $options[$form_id] = $form_title;
            }
        }

        return $options;
    }

    function get_destination_item_label()
    {
        return esc_html__('Event', 'fusewp');
    }

    public function get_destination_items()
    {
        return ['form_submission' => esc_html__('After Form Submission', 'fusewp')];
    }

    public function get_rule_information()
    {
        return '<p>' . sprintf(
                esc_html__('Sync Everest Forms submissions to your email marketing software after form submission. %sLearn more%s',
                    'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-everest-forms-email-marketing/">', '</a>'
            ) . '</p>';
    }

    /** Ensures fusewpEmail/Lead Email address field isn't added multiple times in the mapping UI */
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

    public function get_form_fields($fields)
    {
        $sourceData = $this->get_source_data();

        $source      = $sourceData[0];
        $source_item = $sourceData[1];

        if ($source == $this->id) {

            $bucket = [];

            $form_data = evf()->form->get($source_item, ['content_only' => true]);

            /** @see EVF_Email_Marketing::output_account_fields() */
            $whitelist_fields = [
                'first-name',
                'last-name',
                'text',
                'textarea',
                'select',
                'radio',
                'checkbox',
                'email',
                'address',
                'country',
                'url',
                'name',
                'hidden',
                'date',
                'date-time',
                'phone',
                'number',
                'rating',
                'yes-no',
                'lookup',
                'scale-rating',
                'payment-single',
                'payment-multiple',
                'payment-checkbox',
                'payment-quantity',
                'payment-total',
            ];

            $form_fields = evf_get_form_fields($form_data, apply_filters('everest_forms_email_marketing_whitelist_fields', $whitelist_fields));

            if (is_array($form_fields) && ! empty($form_fields)) {
                foreach ($form_fields as $field_id => $field) {
                    $bucket['Everest Forms']['fseverestforms_' . $field_id] = $field['label'] ?? ucfirst($field['id']) . ' Field';
                }
            }

            // Add meta fields
            $meta_fields = [
                'form_id'      => esc_html__('Form ID', 'fusewp'),
                'entry_id'     => esc_html__('Entry ID', 'fusewp'),
                'date_created' => esc_html__('Entry Created Date', 'fusewp'),
                'user_ip'      => esc_html__('User IP Address', 'fusewp'),
                'user_device'  => esc_html__('User Device', 'fusewp')
            ];

            foreach ($meta_fields as $key => $label) {
                $bucket['Everest Forms']['fseverestforms_' . $key] = $label;
            }

            $userDataBucketId          = esc_html__('WordPress User Data', 'fusewp');
            $bucket[$userDataBucketId] = $fields[$userDataBucketId];

            return apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
        }

        return $fields;
    }

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fseverestforms_')) {
            $field_key = str_replace('fseverestforms_', '', $field_id);
            $value     = $extras[$field_key] ?? '';
        }

        return apply_filters('fusewp_' . $this->id . '_custom_field_data', $value, $field_id, $wp_user_id, $this, $extras);
    }

    public function get_email_field_id($mapped_custom_fields)
    {
        $mappable_data = $mapped_custom_fields['mappable_data'] ?? [];
        $field_values  = $mapped_custom_fields['field_values'] ?? [];

        $email_array_key = array_search('fusewpEmail', $field_values);

        if (false !== $email_array_key && isset($mappable_data[$email_array_key])) {
            return str_replace('fseverestforms_', '', $mappable_data[$email_array_key]);
        }

        return false;
    }

    public function handle_form_submission($entry_id)
    {
        if ( ! $entry_id) return;

        $entry = evf_get_entry($entry_id, true);

        if (empty($entry)) return;

        $submission_data = [
            'form_id'      => $entry->form_id,
            'entry_id'     => $entry_id,
            'user_id'      => $entry->user_id ?? '',
            'date_created' => $entry->date_created ?? '',
            'user_ip'      => $entry->user_ip_address ?? '',
            'user_device'  => $entry->user_device ?? '',
        ];

        $formData = json_decode($entry->fields, true);

        foreach ($formData as $field_id => $field) {
            $submission_data[$field_id] = $field['value_raw'] ?? ($field['value'] ?? '');
        }

        $this->sync_user('form_submission', $submission_data);
    }

    /**
     * @param string $event
     * @param mixed $submission_data
     *
     * @return void
     */
    public function sync_user($event, $submission_data)
    {
        $form_id = $submission_data['form_id'] ?? false;

        if ( ! $form_id) return;

        $user_data = $this->get_mapping_user_data(0, $submission_data);

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
                        $custom_fields      = fusewpVar($destination, $sync_action::CUSTOM_FIELDS_FIELD_ID, []);
                        $evf_email_field_id = $this->get_email_field_id($custom_fields);

                        if ( ! empty($evf_email_field_id) && isset($submission_data[$evf_email_field_id])) {
                            $email_address = $submission_data[$evf_email_field_id];
                            $list_id       = fusewpVar($destination, $sync_action::EMAIL_LIST_FIELD_ID, '');

                            QueueManager::push([
                                'action'                => 'subscribe_user',
                                'source_id'             => $this->id,
                                'rule_id'               => $rule['id'],
                                'destination'           => $destination,
                                'integration'           => $sync_action->get_integration_id(),
                                'mappingUserDataEntity' => $user_data,
                                'extras'                => $submission_data,
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
        $args = [
            'form_id' => $source_item_id,
            'limit'   => max(1, intval($number)),
            'offset'  => ($paged - 1) * max(1, intval($number)),
            'order'   => 'ASC'
        ];

        return evf_search_entries($args);
    }

    public function bulk_sync_handler($item)
    {
        $entry_id = $item['r'];

        $this->handle_form_submission($entry_id);
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
