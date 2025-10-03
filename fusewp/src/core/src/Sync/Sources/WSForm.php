<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;

class WSForm extends AbstractSyncSource
{
    public function __construct()
    {
        $this->title = 'WS Form';
        $this->id    = 'wsform';

        parent::__construct();

        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 999);
        add_filter('fusewp_sync_integration_list_fields_default_data', [$this, 'add_email_default_esp_fields_mapping'], 10, 2);
        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui']);

        add_action('wsf_submit_post_complete', function ($entry) {

            $payload = [
                'entry_id'   => $entry->id,
                'form_id'    => $entry->form_id,
                'created_at' => $entry->date_added,
                'user_id'    => $entry->user_id ?? 0,
            ];

            foreach ($entry->meta as $key => $dta) {
                $payload[$key] = $this->transform_field_value($dta['value'] ?? '');
            }

            $this->sync_user('form_submission', $payload);
        });
    }

    public function get_source_items()
    {
        $forms = [];

        if (class_exists('WS_Form_Form')) {

            global $wpdb;

            $table_name = $wpdb->prefix . 'wsf_form';

            $results = $wpdb->get_results("SELECT id, label FROM $table_name WHERE status = 'publish' ORDER BY label ASC");

            if ( ! empty($results)) {
                foreach ($results as $form) {
                    $forms[$form->id] = $form->label;
                }
            }
        }

        return $forms;
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
                esc_html__('Sync WS Form Forms leads to your email marketing software after form submissionh. %sLearn more%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-wsform-email-marketing/">', '</a>'
            ) . '</p>';
    }

    public static function _get_form_fields($form_id, $prefix = 'fswsforms_')
    {
        $bucket = [];

        if ( ! class_exists('WS_Form_Form')) return $bucket;

        $ws_form     = new \WS_Form_Form();
        $ws_form->id = $form_id;

        $form_object = $ws_form->db_read(true, true);

        if (isset($form_object->groups) && is_array($form_object->groups)) {
            foreach ($form_object->groups as $group) {
                if (isset($group->sections) && is_array($group->sections)) {
                    foreach ($group->sections as $section) {
                        if (isset($section->fields) && is_array($section->fields)) {
                            foreach ($section->fields as $field) {
                                if (isset($field->type) && isset($field->label)) {
                                    if ($field->type == 'submit') continue;

                                    $bucket['WS Form'][$prefix . 'field_' . $field->id] = $field->label;
                                }
                            }
                        }
                    }
                }
            }
        }

        $metaFields = [
            'entry_id'   => esc_html__('Entry ID', 'fusewp'),
            'form_id'    => esc_html__('Form ID', 'fusewp'),
            'form_name'  => esc_html__('Form Name', 'fusewp'),
            'created_at' => esc_html__('Created At', 'fusewp'),
        ];

        foreach ($metaFields as $key => $label) {
            $bucket['WS Form'][$prefix . $key] = $label;
        }

        return $bucket;
    }

    public function get_form_fields($fields)
    {
        $sourceData = $this->get_source_data();

        $source      = $sourceData[0];
        $source_item = $sourceData[1];

        if ($source == $this->id) {
            $bucket = self::_get_form_fields($source_item);

            $userDataBucketId          = esc_html__('WordPress User Data', 'fusewp');
            $bucket[$userDataBucketId] = $fields[$userDataBucketId];

            return apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
        }

        return $fields;
    }

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fswsforms_')) {

            $hashmap = [
                'entry_id'  => $extras['entry_id'] ?? '',
                'form_id'   => $extras['form_id'] ?? '',
                'form_name' => $this->get_form_name($extras['form_id']) ?? '',
            ];

            $field_key = str_replace('fswsforms_', '', $field_id);

            $value = $hashmap[$field_key] ?? $extras[$field_key] ?? '';
        }

        return apply_filters('fusewp_' . $this->id . '_custom_field_data', $value, $field_id, $wp_user_id, $this, $extras);
    }

    private function get_form_name($form_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wsf_form';

        return $wpdb->get_var($wpdb->prepare("SELECT label FROM $table_name WHERE id = %d", $form_id));
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

    public static function get_email_wsf_field_id($mapped_custom_fields)
    {
        $mappable_data = $mapped_custom_fields['mappable_data'] ?? [];
        $field_values  = $mapped_custom_fields['field_values'] ?? [];

        $email_array_key = array_search('fusewpEmail', $field_values);

        if (false !== $email_array_key && isset($mappable_data[$email_array_key])) {
            return str_replace('fswsforms_', '', $mappable_data[$email_array_key]);
        }

        return false;
    }

    public function sync_user($event, $submission)
    {
        $form_id = $submission['form_id'] ?? false;

        if ( ! $form_id) return;

        $user_data = $this->get_mapping_user_data($submission['user_id'] ?? 0, $submission);

        $rule = fusewp_sync_get_rule_by_source(sprintf('%s|%s', $this->id, $form_id));

        $destinations = fusewpVar($rule, 'destinations', [], true);

        if ( ! empty($destinations) && is_string($destinations)) {
            $destinations = json_decode($destinations, true);
        }

        if (is_array($destinations) && ! empty($destinations)) {
            foreach ($destinations as $destination) {
                if (fusewpVar($destination, 'destination_item') != $event) continue;

                $integration = fusewpVar($destination, 'integration', '', true);

                if ( ! empty($integration)) {
                    $integration = fusewp_get_registered_sync_integrations($integration);
                    $sync_action = $integration->get_sync_action();

                    if ($integration instanceof IntegrationInterface) {
                        $custom_fields      = fusewpVar($destination, $sync_action::CUSTOM_FIELDS_FIELD_ID, []);
                        $wsf_email_field_id = self::get_email_wsf_field_id($custom_fields);

                        if (false !== $wsf_email_field_id && isset($submission[$wsf_email_field_id])) {
                            $email_address = $submission[$wsf_email_field_id];

                            $list_id = fusewpVar($destination, $sync_action::EMAIL_LIST_FIELD_ID, '');

                            QueueManager::push([
                                'action'                => 'subscribe_user',
                                'source_id'             => $this->id,
                                'rule_id'               => $rule['id'],
                                'destination'           => $destination,
                                'integration'           => $sync_action->get_integration_id(),
                                'mappingUserDataEntity' => $user_data,
                                'extras'                => $submission,
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
        $number = max(1, intval($number));
        $paged  = max(1, intval($paged));
        $offset = ($paged - 1) * $number;

        global $wpdb;
        $table_name = $wpdb->prefix . 'wsf_submit';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id AS entry_id, form_id, date_added AS created_at, user_id FROM $table_name WHERE form_id = %d LIMIT %d OFFSET %d",
                $source_item_id,
                $number,
                $offset
            )
        );
    }

    public function bulk_sync_handler($item)
    {
        if ( ! empty($item['r']->entry_id)) {

            global $wpdb;

            $table_name = $wpdb->prefix . 'wsf_submit_meta';

            $fields = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_key, meta_value FROM $table_name WHERE parent_id = %d",
                    $item['r']->entry_id
                )
            );

            $submission = (array) $item['r'];

            foreach ($fields as $field) {
                $value = maybe_unserialize($field->meta_value);
                $submission[$field->meta_key] = $this->transform_field_value($value);
            }

            $this->sync_user('form_submission', $submission);
        }
    }

    private function transform_field_value($value)
    {
        // if file upload data, get the file name
        if (is_array($value) && isset($value[0]['name']) && isset($value[0]['handler'])) {
            $value = wp_list_pluck($value, 'name');
        }

        // if value is an array with one item, extract the item as string
        if (is_array($value) && count($value) === 1) {
            $value = $value[0];
        }

        return $value;
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
