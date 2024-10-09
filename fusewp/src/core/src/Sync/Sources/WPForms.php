<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;

class WPForms extends AbstractSyncSource
{
    public function __construct()
    {
        $this->title = 'WPForms';

        $this->id = 'wpforms';

        parent::__construct();

        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 999);

        add_filter('fusewp_sync_integration_list_fields_default_data', [
            $this,
            'add_email_default_esp_fields_mapping'
        ], 10, 2);

        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui']);

        add_action('wpforms_process_complete', function ($fields, $entry, $form_data, $entry_id) {
            $this->sync_user('form_submission', $fields, $entry, $entry_id);

        }, 1, 4);

        add_action('wpforms_user_registration_process_registration_process_completed_after', function ($user_id, $fields, $form_data, $user_data) {
            $entry = ['id' => $form_data['id'] ?? '', 'post_id' => $form_data['entry_meta']['page_id'] ?? ''];
            $this->sync_user('after_registration', $fields, $entry, 0, $user_id);
        }, 1, 4);
    }

    public function get_source_items()
    {
        $forms = wpforms()->get('form')->get();

        $options = [];

        foreach ($forms as $form) {
            $options[$form->ID] = $form->post_title;
        }

        return $options;
    }

    public function get_destination_items()
    {
        $items = [
            'form_submission'    => esc_html__('After Form Submission', 'fusewp'),
            'after_registration' => esc_html__('After User Registration', 'fusewp')
        ];

        if ( ! fusewp_is_premium()) {
            $new_item = [];
            foreach ($items as $key => $value) {
                if ($key == 'form_submission') {
                    $new_item[$key] = $value;
                } else {
                    $new_item['fusewp_disabled_' . $key] = sprintf('%s (%s)', $value, esc_html__('Premium Feature', 'fusewp'));
                }
            }
            $items = $new_item;
        }

        return $items;
    }

    public function get_destination_item_label()
    {
        return esc_html__('Event', 'fusewp');
    }

    public function get_rule_information()
    {
        return '<p>' . sprintf(
                esc_html__('Sync WPForms leads to your email marketing software after form submission, or user registration. %sLearn more%s',
                    'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-wpforms-email-marketing/">', '</a>'
            ) . '</p>';
    }

    public function get_form_fields($fields)
    {
        $bucket = [];

        $sourceData = $this->get_source_data();

        $source      = $sourceData[0];
        $source_item = $sourceData[1];

        if ($source == $this->id) {

            $form_fields = wpforms_get_form_fields($source_item);

            if (is_array($form_fields) && ! empty($form_fields)) {

                foreach ($form_fields as $form_field) {

                    if ('name' === $form_field['type']) {

                        $bucket['WPForms']['fswpforms_' . $form_field['id'] . '_full'] = sprintf(esc_html__('%s (Full)', 'fusewp'), $form_field['label']);

                        // First Name.
                        if (strpos($form_field['format'], 'first') !== false) {
                            $bucket['WPForms']['fswpforms_' . $form_field['id'] . '_first'] = sprintf(esc_html__('%s (First)', 'fusewp'), $form_field['label']);
                        }

                        // Middle Name.
                        if (strpos($form_field['format'], 'middle') !== false) {
                            $bucket['WPForms']['fswpforms_' . $form_field['id'] . '_middle'] = sprintf(esc_html__('%s (Middle)', 'fusewp'), $form_field['label']);

                        }

                        // Last Name.
                        if (strpos($form_field['format'], 'last') !== false) {
                            $bucket['WPForms']['fswpforms_' . $form_field['id'] . '_last'] = sprintf(esc_html__('%s (Last)', 'fusewp'), $form_field['label']);
                        }

                    } else {

                        $bucket['WPForms']['fswpforms_' . $form_field['id']] = $form_field['label'];
                    }
                }
            }

            $metaFields = [
                'entry_id'   => esc_html__('Entry ID', 'fusewp'),
                'form_id'    => esc_html__('Form ID', 'fusewp'),
                'form_title' => esc_html__('Form Name', 'fusewp'),
                'page_id'    => esc_html__('Page ID', 'fusewp'),
                'page_url'   => esc_html__('Page URL', 'fusewp'),
            ];

            foreach ($metaFields as $key => $label) {
                $bucket['WPForms']['fswpforms_' . $key] = $label;
            }

            $userDataBucketId          = esc_html__('WordPress User Data', 'fusewp');
            $bucket[$userDataBucketId] = $fields[$userDataBucketId];

            return apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
        }

        return $fields;
    }

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fswpforms_')) {

            $hashmap = [
                'entry_id'   => $extras['real_entry_id'] ?? '',
                'form_id'    => $extras['entry']['id'] ?? '',
                'form_title' => isset($extras['entry']['id']) ? get_the_title($extras['entry']['id']) : '',
                'page_id'    => $extras['entry']['post_id'] ?? '',
                'page_url'   => isset($extras['entry']['post_id']) ? get_permalink($extras['entry']['post_id']) : ''
            ];

            $field_key = str_replace('fswpforms_', '', $field_id);

            if (isset($hashmap[$field_key])) {
                $value = $hashmap[$field_key];
            } elseif (strpos($field_key, '_full') !== false) {
                $value = $extras['fieldsData'][str_replace('_full', '', $field_key)]['value'];
            } elseif (strpos($field_key, '_first') !== false) {
                $value = $extras['fieldsData'][str_replace('_first', '', $field_key)]['first'];
            } elseif (strpos($field_key, '_middle') !== false) {
                $value = $extras['fieldsData'][str_replace('_middle', '', $field_key)]['middle'];
            } elseif (strpos($field_key, '_last') !== false) {
                $value = $extras['fieldsData'][str_replace('_last', '', $field_key)]['last'];
            } else {
                $value = $extras['fieldsData'][$field_key]['value'];
            }
        }

        return apply_filters('fusewp_' . $this->id . '_custom_field_data', $value, $field_id, $wp_user_id, $this, $extras);
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

    public function add_email_field_mapping_ui($integration_contact_fields)
    {
        if ($this->is_email_field_found($integration_contact_fields) === false) {

            $field = (new ContactFieldEntity())
                ->set_id('fusewpEmail')
                ->set_name(esc_html__('Lead Email Address', 'fusewp'));

            array_unshift($integration_contact_fields, $field);
        }

        return $integration_contact_fields;
    }

    protected function get_email_wpf_field_id($mapped_custom_fields)
    {
        $mappable_data = $mapped_custom_fields['mappable_data'] ?? [];
        $field_values  = $mapped_custom_fields['field_values'] ?? [];

        $email_array_key = array_search('fusewpEmail', $field_values);

        if (false !== $email_array_key && isset($mappable_data[$email_array_key])) {
            return str_replace('fswpforms_', '', $mappable_data[$email_array_key]);
        }

        return false;
    }

    public function sync_user($event, $fieldsData, $entry, $entry_id, $user_id = 0)
    {
        $form_id = $entry['id'] ?? false;

        if ( ! $form_id) return;

        $extras = [
            'fieldsData'    => $fieldsData,
            'entry'         => $entry,
            'real_entry_id' => $entry_id
        ];

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

                        $wpf_email_field_id = $this->get_email_wpf_field_id($custom_fields);

                        if (false !== $wpf_email_field_id && isset($fieldsData[$wpf_email_field_id]['value'])) {

                            $email_address = $fieldsData[$wpf_email_field_id]['value'];

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
        // Check if WPForms is active and it's pro version.
        // Only pro version stores form data in db.
        if ( ! function_exists('wpforms') || ! wpforms()->is_pro()) {
            return [];
        }

        $number = max(1, intval($number));
        $paged  = max(1, intval($paged));

        $offset = ($paged - 1) * $number;

        return wpforms()->get('entry')->get_entries([
            'form_id' => $source_item_id,
            'number'  => $number,
            'offset'  => $offset,
        ]);
    }

    public function bulk_sync_handler($item)
    {
        $this->sync_user(
            'form_submission',
            wpforms_decode($item['r']->fields),
            ['id' => $item['si']],
            $item['r']->entry_id
        );
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
