<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;

class FormidableForms extends AbstractSyncSource
{
    public function __construct()
    {
        $this->id    = 'formidable';
        $this->title = 'Formidable Forms';

        parent::__construct();

        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 10, 3);
        add_filter('fusewp_sync_integration_list_fields_default_data', [$this, 'add_email_default_esp_fields_mapping'], 10, 4);
        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui'], 10, 3);

        add_action('frm_after_create_entry', [$this, 'handle_form_submission']);
    }

    /**
     * @return array
     */
    function get_source_items()
    {
        $forms     = \FrmForm::get_published_forms();
        $form_list = [];

        if ( ! empty($forms) && is_array($forms)) {
            foreach ($forms as $form) {
                $form_list[$form->id] = $form->name;
            }
        }

        return $form_list;
    }

    /**
     * @return array
     */
    function get_destination_items()
    {
        return [
            'form_submission' => esc_html__('After Form Submission', 'fusewp')
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
     * @return string
     */
    function get_rule_information()
    {
        return '<p>' . sprintf(
                esc_html__('Sync Formidable Forms submissions to your email marketing software after form submission. %sLearn more%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-formidable-forms-email-marketing/">', '</a>'
            ) . '</p>';
    }

    /**
     * Get form fields for mapping
     *
     * @param $fields
     *
     * @return array
     */
    public function get_form_fields($fields)
    {
        $sourceData = $this->get_source_data();

        $source      = $sourceData[0];
        $source_item = $sourceData[1];

        if ($source == $this->id) {

            $metaFields = [
                'entry_id'   => esc_html__('Entry ID', 'fusewp'),
                'form_id'    => esc_html__('Form ID', 'fusewp'),
                'form_name'  => esc_html__('Form Title', 'fusewp'),
                'created_at' => esc_html__('Entry Created Date', 'fusewp'),
            ];

            $bucket = [];

            $form_fields = \FrmField::get_all_for_form($source_item);

            if ( ! empty($form_fields)) {
                foreach ($form_fields as $field) {
                    // Skip non-input field types
                    if (in_array($field->type, ['break', 'divider', 'end_divider', 'html', 'captcha', 'form'])) {
                        continue;
                    }

                    if ($field->type == 'name') {
                        $bucket['Formidable Forms']['fsformidableforms_' . $field->id . '_first']  = $field->name . ' - ' . esc_html__('First Name', 'fusewp');
                        $bucket['Formidable Forms']['fsformidableforms_' . $field->id . '_middle'] = $field->name . ' - ' . esc_html__('Middle Name', 'fusewp');
                        $bucket['Formidable Forms']['fsformidableforms_' . $field->id . '_last']   = $field->name . ' - ' . esc_html__('Last Name', 'fusewp');
                        continue;
                    }

                    $bucket['Formidable Forms']['fsformidableforms_' . $field->id] = $field->name;
                }
            }

            foreach ($metaFields as $key => $label) {
                $bucket['Formidable Forms']['fsformidableforms_' . $key] = $label;
            }

            $userDataBucketId = esc_html__('WordPress User Data', 'fusewp');

            $bucket[$userDataBucketId] = $fields[$userDataBucketId];

            return apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
        }

        return $fields;
    }

    /**
     * Add email field mapping UI
     *
     * @param $integration_contact_fields
     *
     * @return array
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
     * Add default email field mapping
     *
     * @param $fields
     * @param string $source_id
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

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fsformidableforms_')) {
            $field_key = str_replace('fsformidableforms_', '', $field_id);
            $value     = $extras[$field_key] ?? '';
        }

        return apply_filters('fusewp_' . $this->id . '_custom_field_data', $value, $field_id, $wp_user_id, $this, $extras);
    }

    /**
     * Handle form submission
     *
     * @param int $entry_id
     */
    public function handle_form_submission($entry_id)
    {
        $this->sync_user('form_submission', $entry_id);
    }

    /**
     * Sync user to email marketing service
     *
     * @param string $event
     * @param int $entry_id
     */
    public function sync_user($event, $entry_id)
    {
        $entry = \FrmEntry::getOne($entry_id, true);

        if ( ! $entry || ! $entry->form_id) return;

        $entry_data = [
            'entry_id'   => $entry_id,
            'form_id'    => $entry->form_id,
            'created_at' => $entry->created_at,
            'form_name'  => $entry->form_name,
            'user_id'    => $entry->user_id ?: 0
        ];

        // Get entry meta data
        if (isset($entry->metas) && is_array($entry->metas)) {

            foreach ($entry->metas as $field_id => $meta_value) {

                if (\FrmField::get_type($field_id) == 'file' && ! empty($meta_value)) {
                    $meta_value = wp_get_attachment_url($meta_value);
                }

                if (\FrmField::get_type($field_id) == 'name') {
                    $entry_data[$field_id . '_first']  = $meta_value['first'] ?? '';
                    $entry_data[$field_id . '_middle'] = $meta_value['middle'] ?? '';
                    $entry_data[$field_id . '_last']   = $meta_value['last'] ?? '';
                    continue;
                }

                $entry_data[$field_id] = $meta_value;
            }
        }

        $user_data = $this->get_mapping_user_data($entry_data['user_id'] ?? 0, $entry_data);

        $rule = fusewp_sync_get_rule_by_source(sprintf('%s|%s', $this->id, $entry->form_id));

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
                        $custom_fields  = fusewpVar($destination, $sync_action::CUSTOM_FIELDS_FIELD_ID, []);
                        $email_field_id = self::get_email_field_id($custom_fields);

                        if ( ! empty($email_field_id) && isset($entry_data[$email_field_id])) {
                            $email_address = $entry_data[$email_field_id];
                            $list_id       = fusewpVar($destination, $sync_action::EMAIL_LIST_FIELD_ID, '');

                            QueueManager::push([
                                'action'                => 'subscribe_user',
                                'source_id'             => $this->id,
                                'rule_id'               => $rule['id'],
                                'destination'           => $destination,
                                'integration'           => $sync_action->get_integration_id(),
                                'mappingUserDataEntity' => $user_data,
                                'extras'                => $entry_data,
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
     * @return mixed
     */
    public function get_bulk_sync_data($source_item_id, $paged, $number)
    {
        $number = max(1, intval($number));
        $paged  = max(1, intval($paged));
        $offset = ($paged - 1) * $number;

        $where    = ['it.form_id' => $source_item_id];
        $order_by = ' ORDER BY it.id DESC';
        $limit    = ' LIMIT ' . $offset . ', ' . $number;

        try {
            return \FrmEntry::getAll($where, $order_by, $limit, true);
        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param $item
     *
     * @return void
     */
    public function bulk_sync_handler($item)
    {
        if (empty($item['r']) || ! is_object($item['r'])) return;

        $this->sync_user('form_submission', $item['r']->id);
    }

    public static function get_email_field_id($mapped_custom_fields)
    {
        $mappable_data = $mapped_custom_fields['mappable_data'] ?? [];
        $field_values  = $mapped_custom_fields['field_values'] ?? [];

        $email_array_key = array_search('fusewpEmail', $field_values);

        if (false !== $email_array_key && isset($mappable_data[$email_array_key])) {
            return str_replace('fsformidableforms_', '', $mappable_data[$email_array_key]);
        }

        return false;
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
