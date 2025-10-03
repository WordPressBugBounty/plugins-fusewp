<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;
use SRFM\Inc\Database\Tables\Entries;

class SureForms extends AbstractSyncSource
{
    public function __construct()
    {
        $this->id    = 'sure_forms';
        $this->title = 'SureForms';

        parent::__construct();

        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 999);
        add_filter('fusewp_sync_integration_list_fields_default_data', [$this, 'add_email_default_esp_fields_mapping'], 10, 2);
        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui']);

        add_action('srfm_form_submit', function ($entry) {

            $submission_data = array_merge($entry['data'], [
                'form_id'   => $entry['form_id'],
                'entry_id'  => $entry['entry_id'],
                'form_name' => $entry['form_name'],
            ]);

            $this->sync_user('form_submission', $submission_data);
        });
    }

    /**
     * @return array
     */
    function get_source_items()
    {
        $options = [];

        $forms = get_posts([
            'post_type'      => 'sureforms_form',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ]);

        if ( ! empty($forms) && is_array($forms)) {
            foreach ($forms as $form_id) {
                $form_title        = get_the_title($form_id);
                $options[$form_id] = ! empty($form_title) ? $form_title : sprintf('Form #%d', $form_id);
            }
        }

        return $options;
    }

    /**
     * @return array
     */
    function get_destination_items()
    {
        return ['form_submission' => esc_html__('After Form Submission', 'fusewp')];
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
                esc_html__('Sync SureForms leads to your email marketing software after form submission. %sLearn more%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-sureforms-email-marketing/">', '</a>'
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
            $metaFields = [
                'entry_id'   => esc_html__('Entry ID', 'fusewp'),
                'form_id'    => esc_html__('Form ID', 'fusewp'),
                'form_name'  => esc_html__('Form Name', 'fusewp'),
                'created_at' => esc_html__('Entry Created Date', 'fusewp'),
            ];

            $bucket = [];

            // Get form content and parse blocks to extract field information
            $form_post = get_post($source_item);
            if ($form_post && ! empty($form_post->post_content)) {
                $blocks = parse_blocks($form_post->post_content);
                $this->extract_form_fields_from_blocks($blocks, $bucket);
            }

            foreach ($metaFields as $key => $label) {
                $bucket['SureForms']['fssureforms_' . $key] = $label;
            }

            $userDataBucketId          = esc_html__('WordPress User Data', 'fusewp');
            $bucket[$userDataBucketId] = $fields[$userDataBucketId];

            return apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
        }

        return $fields;
    }

    /**
     * Extract form fields from SureForms blocks
     */
    private function extract_form_fields_from_blocks($blocks, &$bucket)
    {
        foreach ($blocks as $block) {

            if (isset($block['blockName']) && strpos($block['blockName'], 'srfm/') === 0) {

                if (in_array($block['blockName'], ['srfm/repeater'])) continue;

                $field_type = str_replace('srfm/', '', $block['blockName']);
                $attrs      = $block['attrs'] ?? [];

                // Get field label and slug
                $field_label = $attrs['label'] ?? '';
                $field_slug  = $attrs['slug'] ?? '';

                if ( ! empty($field_slug)) {
                    if (empty($field_label)) {
                        $field_label = ucfirst(str_replace(['-', '_'], ' ', $field_type));
                    }
                    $bucket['SureForms']['fssureforms_' . $field_slug] = $field_label;
                }
            }

            // Recursively check inner blocks
            if ( ! empty($block['innerBlocks'])) {
                $this->extract_form_fields_from_blocks($block['innerBlocks'], $bucket);
            }
        }
    }

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fssureforms_')) {
            $field_key = str_replace('fssureforms_', '', $field_id);

            if ($field_key == 'created_at') {
                $value = $extras[$field_key] ?? current_time('mysql');
            } else {
                $value = $extras[$field_key] ?? '';
            }
        }

        return apply_filters('fusewp_' . $this->id . '_custom_field_data', $value, $field_id, $wp_user_id, $this, $extras);
    }

    public static function get_email_field_id($mapped_custom_fields)
    {
        $mappable_data = $mapped_custom_fields['mappable_data'] ?? [];
        $field_values  = $mapped_custom_fields['field_values'] ?? [];

        $email_array_key = array_search('fusewpEmail', $field_values);

        if (false !== $email_array_key && isset($mappable_data[$email_array_key])) {
            return str_replace('fssureforms_', '', $mappable_data[$email_array_key]);
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
                if (fusewpVar($destination, 'destination_item') != $event) continue;

                $integration = fusewpVar($destination, 'integration', '', true);

                if ( ! empty($integration)) {
                    $integration = fusewp_get_registered_sync_integrations($integration);
                    $sync_action = $integration->get_sync_action();

                    if ($integration instanceof IntegrationInterface) {
                        $custom_fields            = fusewpVar($destination, $sync_action::CUSTOM_FIELDS_FIELD_ID, []);
                        $sureforms_email_field_id = self::get_email_field_id($custom_fields);

                        if ( ! empty($sureforms_email_field_id) && isset($submission_data[$sureforms_email_field_id])) {
                            $email_address = $submission_data[$sureforms_email_field_id];
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
        if (class_exists('\SRFM\Inc\Database\Tables\Entries')) {
            $entries_table = Entries::get_instance();

            $args = [
                'form_id' => $source_item_id,
                'status'  => ['unread', 'read'], // Exclude trash
                'limit'   => max(1, intval($number)),
                'offset'  => (max(1, intval($paged)) - 1) * max(1, intval($number)),
                'orderby' => 'created_at',
                'order'   => 'ASC'
            ];

            return $entries_table->get_all($args);
        }

        return [];
    }

    public function bulk_sync_handler($item)
    {
        $entry   = $item['r'] ?? null;
        $form_id = $item['si'] ?? null;

        if ( ! $entry || ! $form_id) return;

        $raw_form_data = $entry['form_data'] ?? [];

        $transformed_data   = [];
        $address_components = [];

        foreach ($raw_form_data as $key => $value) {
            // Parse SureForms field structure: srfm-{type}-{id}-lbl-{encoded_label}-{field_name}
            if (strpos($key, '-lbl-') !== false) {
                $parts = explode('-lbl-', $key);

                if (count($parts) >= 2) {
                    // Extract the field name from the second part
                    $label_and_field = $parts[1];
                    $tokens          = explode('-', $label_and_field);

                    if (count($tokens) > 1) {
                        // Everything after the encoded label is the field name
                        $field_name                    = implode('-', array_slice($tokens, 1));
                        $transformed_data[$field_name] = $value;

                        // Collect address components
                        if (in_array($field_name,
                            ['address-line-1', 'address-line-2', 'city', 'state', 'postal-code', 'country'])) {
                            if ( ! empty($value)) {
                                $address_components[$field_name] = $value;
                            }
                        }
                    }
                }
            }
        }

        if ( ! empty($address_components)) {
            $address_parts = [];
            $address_order = ['address-line-1', 'address-line-2', 'city', 'state', 'postal-code', 'country'];

            foreach ($address_order as $component) {
                if ( ! empty($address_components[$component])) {
                    $address_parts[] = $address_components[$component];
                }
            }

            if ( ! empty($address_parts)) {
                $transformed_data['address'] = implode(', ', $address_parts);
            }
        }

        // Add metadata
        $transformed_data['form_id']    = $form_id;
        $transformed_data['entry_id']   = $entry['ID'] ?? '';
        $transformed_data['form_name']  = get_the_title($form_id) ?: '';
        $transformed_data['created_at'] = $entry['created_at'] ?? '';

        $this->sync_user('form_submission', $transformed_data);
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
