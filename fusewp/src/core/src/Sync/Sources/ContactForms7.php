<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;
use WPCF7_Submission;

class ContactForms7 extends AbstractSyncSource
{
    public function __construct()
    {
        $this->title = 'Contact Forms 7';

        $this->id = 'contact_forms_7';

        parent::__construct();

        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 999);

        add_filter(
            'fusewp_sync_integration_list_fields_default_data',
            [$this, 'add_email_default_esp_fields_mapping'],
            10, 2
        );

        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui']);

        add_action('wpcf7_submit', function ($contact_form, $result) {
            $this->sync_user('form_submission', $contact_form, $result);
        }, 1, 2);
    }

    /**
     * @return array
     */
    function get_source_items()
    {
        $options = [];

        // Get all Contact Form 7 forms
        $args = [
            'post_type'      => 'wpcf7_contact_form',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        $forms = get_posts($args);

        if ( ! empty($forms)) {
            foreach ($forms as $form) {
                $options[$form->ID] = $form->post_title;
            }
        }

        return $options;
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

    /**
     * @return array
     */
    function get_destination_items()
    {
        return [
            'form_submission' => esc_html__('After Form Submission', 'fusewp'),
        ];
    }

    /**
     * @return string
     */
    function get_destination_item_label()
    {
        return esc_html__('Event', 'fusewp');
    }

    /**
     * @return mixed
     */
    function get_rule_information()
    {
        return '<p>' . sprintf(
                esc_html__('Sync Contact Form 7 submissions to your email marketing software after form submission. %sLearn more%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-contact-form-7-email-marketing/">', '</a>'
            ) . '</p>';
    }

    public function get_form_fields($fields)
    {
        $bucket = [];

        $meta_fields = [
            'id'         => esc_html__('Form ID', 'fusewp'),
            'title'      => esc_html__('Form Title', 'fusewp'),
            'remote_ip'  => esc_html__('Lead IP Address', 'fusewp'),
            'user_agent' => esc_html__('User Agent', 'fusewp'),
            'url'        => esc_html__('Page URL', 'fusewp'),
            'timestamp'  => esc_html__('Submission Time', 'fusewp'),
        ];

        $sourceData = $this->get_source_data();

        $source      = $sourceData[0];
        $source_item = $sourceData[1];

        if ($source == $this->id) {

            $contact_form = \WPCF7_ContactForm::get_instance($source_item);

            if ($contact_form) {

                $form_fields = $contact_form->scan_form_tags();

                if ( ! empty($form_fields)) {

                    foreach ($form_fields as $field) {
                        if ( ! empty($field['name'])) {
                            $bucket['Contact Form 7']["fscf7_" . $field['name']] = $field['name'];
                        }
                    }
                }

                foreach ($meta_fields as $key => $label) {
                    $bucket['Contact Form 7']["fscf7_" . $key] = $label;
                }

                $userDataBucketId          = esc_html__('WordPress User Data', 'fusewp');
                $bucket[$userDataBucketId] = $fields[$userDataBucketId];

                return apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
            }
        }

        return $fields;
    }

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fscf7_')) {

            $field_key = str_replace('fscf7_', '', $field_id);

            $value = $extras[$field_key] ?? '';
        }

        return apply_filters('fusewp_' . $this->id . '_custom_field_data', $value, $field_id, $wp_user_id, $this, $extras);
    }

    protected function get_email_field_id($mapped_custom_fields)
    {
        $mappable_data = $mapped_custom_fields['mappable_data'] ?? [];
        $field_values  = $mapped_custom_fields['field_values'] ?? [];

        $email_array_key = array_search('fusewpEmail', $field_values);

        if (false !== $email_array_key && isset($mappable_data[$email_array_key])) {
            return str_replace('fscf7_', '', $mappable_data[$email_array_key]);
        }

        return false;
    }

    /**
     * @param $event
     * @param \WPCF7_ContactForm $contact_form
     * @param $result
     *
     * @return void
     */
    public function sync_user($event, $contact_form, $result)
    {
        // status can be spam or validation_failed etc but we only want sync to occur on either mail sent or failed.
        if (empty($result['status']) || ! in_array($result['status'], ['mail_sent', 'mail_failed'])) {
            return;
        }

        $form_id = $contact_form->id();

        $extras = [
            'id'         => $form_id,
            'title'      => $contact_form->title(),
            'remote_ip'  => '',
            'user_agent' => '',
            'url'        => '',
            'timestamp'  => '',
        ];

        $submission = WPCF7_Submission::get_instance();

        // $submission will not be available for bulk sync and the result object will have submission instead.
        if ($submission) {

            $posted_data = $submission->get_posted_data();

            $current_user_id = $submission->get_meta('current_user_id');

            $timestamp = $submission->get_meta('timestamp');

            if ( ! empty($timestamp)) $timestamp = gmdate('Y-m-d H:i:s', $timestamp);

            $submission_data = [
                'remote_ip'  => $submission->get_meta('remote_ip'),
                'user_agent' => $submission->get_meta('user_agent'),
                'url'        => $submission->get_meta('url'),
                'timestamp'  => $timestamp,
            ];

            $extras = array_merge($extras, $submission_data);

        } else {
            $current_user_id = 0;
            $posted_data     = $result;
        }

        $user_id = $current_user_id ?: 0;

        $extras = array_merge($extras, $posted_data);

        $user_data = $this->get_mapping_user_data($user_id, $extras);

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

                        $email_field_id = $this->get_email_field_id($custom_fields);

                        if ( ! empty($email_field_id) && isset($posted_data[$email_field_id])) {
                            $email_address = $posted_data[$email_field_id];

                            $list_id = fusewpVar($destination, $sync_action::EMAIL_LIST_FIELD_ID, '');

                            QueueManager::push([
                                'action'                => 'subscribe_user',
                                'source_id'             => $this->id,
                                'rule_id'               => $rule['id'],
                                'destination'           => $destination,
                                'integration'           => $sync_action->get_integration_id(),
                                'mappingUserDataEntity' => $user_data,
                                'extras'                => $extras,
                                'list_id'               => $list_id,
                                'email_address'         => $email_address
                            ], 5, 1);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $source_item_id
     * @param $paged
     * @param $number
     *
     * @return array
     */
    public function get_bulk_sync_data($source_item_id, $paged, $number)
    {
        // Check if CFDB7 plugin exists
        if (class_exists('\CFDB7_Wp_Sub_Page')) {
            global $wpdb;
            $cfdb       = apply_filters('cfdb7_database', $wpdb);
            $table_name = $cfdb->prefix . 'db7_forms';

            // Make sure the table exists
            if ($cfdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) return [];

            $number = max(1, intval($number));
            $paged  = max(1, intval($paged));
            $start  = ($paged - 1) * $number;

            $query  = "SELECT * FROM $table_name";
            $params = [];

            // If a specific form is selected, filter by it
            if ( ! empty($source_item_id)) {
                $query    .= " WHERE form_post_id = %d";
                $params[] = absint($source_item_id);
            }

            $query    .= " ORDER BY form_id DESC LIMIT %d, %d";
            $params[] = $start;
            $params[] = $number;

            // Get CFDB7 submissions
            $submissions = $cfdb->get_results($cfdb->prepare($query, $params), OBJECT);

            if ( ! empty($submissions)) return $submissions;
        }

        return [];
    }

    /**
     * @param $item
     *
     * @return void
     */
    public function bulk_sync_handler($item)
    {
        if (empty($item['r'])) return;

        if (is_object($item['r']) && isset($item['r']->form_value)) {

            $submission = $item['r'];
            $form_id    = $submission->form_post_id;

            if (empty($form_id)) return;

            // Get the Contact Form 7 form object
            $contact_form = \WPCF7_ContactForm::get_instance($form_id);

            if ( ! $contact_form) return;

            // Unserialize the form data
            $form_data = unserialize($submission->form_value);

            if ( ! is_array($form_data)) return;

            $this->sync_user('form_submission', $contact_form, array_merge($form_data, ['status' => 'mail_sent']));
        }
    }

    /**
     * @return self
     */
    static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
