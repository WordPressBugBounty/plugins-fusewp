<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;
use NF_Database_Models_Field;

class NinjaForms extends AbstractSyncSource
{
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Ninja Forms';

        $this->id = 'ninja_forms';

        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 999);

        add_filter(
            'fusewp_sync_integration_list_fields_default_data',
            [$this, 'add_email_default_esp_fields_mapping'],
            10, 2
        );

        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui']);

        add_action('ninja_forms_after_submission', [$this, 'handle_form_submission']);
    }

    public function get_source_items()
    {
        $options = [];

        $forms = Ninja_Forms()->form()->get_forms();

        if (is_array($forms) && ! empty($forms)) {

            foreach ($forms as $form) {
                $options[$form->get_id()] = $form->get_setting('title');
            }
        }

        return $options;
    }

    public function get_destination_items()
    {
        return ['form_submission' => esc_html__('After Form Submission', 'fusewp')];
    }

    public function get_destination_item_label()
    {
        return esc_html__('Event', 'fusewp');
    }

    public function get_rule_information()
    {
        return '<p>' . sprintf(
                esc_html__('Sync Ninja Forms leads to your email marketing software after form submission. %sLearn more%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-ninja-forms-email-marketing/">', '</a>'
            ) . '</p>';
    }

    public function handle_form_submission($form_data)
    {
        if (empty($form_data) || ! isset($form_data['form_id'])) return;

        $form_id     = $form_data['form_id'];
        $fields_data = $form_data['fields'] ?? [];

        $submission_data = [
            'form_id'      => $form_id,
            'date_created' => current_time('mysql'),
        ];

        // Process form fields and extract values
        if (is_array($fields_data)) {
            foreach ($fields_data as $field_id => $field_data) {
                if (isset($field_data['value'])) {
                    $submission_data[$field_id] = $field_data['value'];
                }
            }
        }

        $this->sync_user('form_submission', $submission_data);
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
        $souceData = $this->get_source_data();

        $source      = $souceData[0];
        $source_item = $souceData[1];

        if ($source == $this->id) {
            $metaFields = [
                'form_id'      => esc_html__('Form ID', 'fusewp'),
                'date_created' => esc_html__('Entry Created Date', 'fusewp'),
            ];

            $bucket = [];

            $form = Ninja_Forms()->form($source_item)->get();

            if ($form) {

                $form_fields = Ninja_Forms()->form($source_item)->get_fields();

                if (is_array($form_fields)) {
                    foreach ($form_fields as $field) {
                        $field_id    = $field->get_id();
                        $field_label = $field->get_setting('label');
                        $field_type  = $field->get_setting('type');

                        if ( ! empty($field_label)) {
                            $bucket['Ninja Forms']['fsninjaforms_' . $field_id] = $field_label;
                        } else {
                            $bucket['Ninja Forms']['fsninjaforms_' . $field_id] = ucfirst($field_type) . ' Field';
                        }
                    }
                }
            }

            foreach ($metaFields as $key => $label) {
                $bucket['Ninja Forms']['fsninjaforms_' . $key] = $label;
            }

            $userDataBucketId          = esc_html__('WordPress User Data', 'fusewp');
            $bucket[$userDataBucketId] = $fields[$userDataBucketId];

            return apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
        }

        return $fields;
    }

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fsninjaforms_')) {
            $field_key = str_replace('fsninjaforms_', '', $field_id);
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
            return str_replace('fsninjaforms_', '', $mappable_data[$email_array_key]);
        }

        return false;
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
                        $custom_fields     = fusewpVar($destination, $sync_action::CUSTOM_FIELDS_FIELD_ID, []);
                        $nf_email_field_id = $this->get_email_field_id($custom_fields);

                        if ( ! empty($nf_email_field_id) && isset($submission_data[$nf_email_field_id])) {
                            $email_address = $submission_data[$nf_email_field_id];
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
        // Get Ninja Forms submissions for the specific form
        return get_posts([
            'post_type'      => 'nf_sub',
            'meta_query'     => [
                [
                    'key'     => '_form_id',
                    'value'   => $source_item_id,
                    'compare' => '='
                ]
            ],
            'posts_per_page' => max(1, intval($number)),
            'paged'          => max(1, intval($paged)),
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'ASC',
            'fields'         => 'ids'
        ]);
    }

    public function bulk_sync_handler($item)
    {
        $submission_id = $item['r'];
        $form_id       = $item['si'];

        $submission_data = [];

        $fields = Ninja_Forms()->form($form_id)->get_fields();

        if ( ! empty($fields) && is_array($fields)) {

            foreach ($fields as $field) {
                /** @var NF_Database_Models_Field $field */
                $submission_data[$field->get_id()] = Ninja_Forms()->form()->get_sub($submission_id)->get_field_value($field->get_id());
            }
        }

        // Add submission metadata
        $submission_data['form_id']      = $form_id;
        $submission_data['date_created'] = get_post_field('post_date', $submission_id);

        $this->sync_user('form_submission', $submission_data);
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
