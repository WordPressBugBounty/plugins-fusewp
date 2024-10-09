<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;

class GravityForms extends AbstractSyncSource
{
    public function __construct($bypass = false)
    {
        parent::__construct();

        $this->title = 'Gravity Forms';

        $this->id = 'gravity_forms';

        // using our own fusewp_sync_mappable_data filter cos we only want to return GF fields
        add_filter('fusewp_sync_mappable_data', [$this, 'get_form_fields'], 999);

        add_filter('fusewp_sync_integration_list_fields_default_data', [
            $this,
            'add_email_default_esp_fields_mapping'
        ], 10, 2);

        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_email_field_mapping_ui']);

        if ( ! $bypass) {

            add_action('gform_after_submission', function ($entry, $form) {
                $this->sync_user('form_submission', $entry);
            }, 1, 2);

            add_action('gform_post_payment_completed', function ($entry, $action) {
                $this->sync_user('after_payment', $entry);
            }, 1, 2);

            add_action('gform_post_payment_refunded', function ($entry, $action) {
                $this->sync_user('payment_refunded', $entry);
            }, 1, 2);

            // remove core user_register hook
            add_filter('gform_disable_registration', function ($val) {
                remove_action('user_register', [WPUserRoles::get_instance(), 'user_register_callback'], 9999);

                return $val;
            });

            add_action('gform_user_registered', function ($user_id, $feed, $entry) {
                $this->sync_user('after_registration', $entry, $user_id);
            }, 1, 3);
        }
    }

    public function get_source_items()
    {
        $options = [];

        $collection = \GFAPI::get_forms();

        if (is_array($collection) && ! empty($collection)) {

            foreach ($collection as $item) {
                $options[$item['id']] = $item['title'];
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

    /**
     * @param $fields
     * @param $source_id
     *
     * @return mixed
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

    public function get_destination_items()
    {
        $items = [
            'form_submission'    => esc_html__('After Form Submission', 'fusewp'),
            'after_payment'      => esc_html__('After Successful Payment', 'fusewp'),
            'payment_refunded'   => esc_html__('After Payment Refund', 'fusewp'),
            'after_registration' => esc_html__('After User Registration', 'fusewp'),
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
                esc_html__('Sync Gravity Forms leads to your email marketing software after form submission, successful payment, payment refund, or user registration. %sLearn more%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-gravity-forms-email-marketing/">', '</a>'
            ) . '</p>';
    }

    public function get_form_fields($fields)
    {
        $bucket = [];

        $metaFields = [
            'id'             => esc_html__('Entry ID', 'fusewp'),
            'form_id'        => esc_html__('Form ID', 'fusewp'),
            'date_created'   => esc_html__('Entry Created Date', 'fusewp'),
            'ip'             => esc_html__('User IP Address', 'fusewp'),
            'source_url'     => esc_html__('Source URL', 'fusewp'),
            'currency'       => esc_html__('Currency', 'fusewp'),
            'payment_status' => esc_html__('Payment Status', 'fusewp'),
            'payment_date'   => esc_html__('Payment Date', 'fusewp'),
            'payment_amount' => esc_html__('Payment Amount', 'fusewp'),
            'transaction_id' => esc_html__('Transaction ID', 'fusewp'),
            'payment_method' => esc_html__('Payment Method', 'fusewp'),
        ];

        if (class_exists('\GFAPI')) {

            $souceData = $this->get_source_data();

            $source      = $souceData[0];
            $source_item = $souceData[1];

            if ($source == $this->id) {

                $form = \GFAPI::get_form($source_item);

                if (isset($form['fields'])) {

                    foreach ($form['fields'] as $inner_field) {

                        $inputs = $inner_field->get_entry_inputs();

                        if (is_array($inputs)) {

                            foreach ($inputs as $input) {
                                $bucket['Gravity Forms']['fsgravityforms_' . $input['id']] = $inner_field->label . ': ' . $input['label'];
                            }

                        } else {
                            $bucket['Gravity Forms']['fsgravityforms_' . $inner_field->id] = $inner_field->label;
                        }
                    }
                }

                foreach ($metaFields as $key => $label) {
                    $bucket['Gravity Forms']['fsgravityforms_' . $key] = $label;
                }

                $userDataBucketId = esc_html__('WordPress User Data', 'fusewp');

                $bucket[$userDataBucketId] = $fields[$userDataBucketId];

                return apply_filters('fusewp_' . $this->id . '_fields', $bucket, $this);
            }
        }

        return $fields;
    }

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        if (strstr($field_id, 'fsgravityforms_')) {

            $field_key = str_replace('fsgravityforms_', '', $field_id);

            $value = $extras[$field_key] ?? '';
        }

        return apply_filters('fusewp_' . $this->id . '_custom_field_data', $value, $field_id, $wp_user_id, $this, $extras);
    }

    protected function get_email_gf_field_id($mapped_custom_fields)
    {
        $mappable_data = $mapped_custom_fields['mappable_data'] ?? [];
        $field_values  = $mapped_custom_fields['field_values'] ?? [];

        $email_array_key = array_search('fusewpEmail', $field_values);

        if (false !== $email_array_key && isset($mappable_data[$email_array_key])) {
            return str_replace('fsgravityforms_', '', $mappable_data[$email_array_key]);
        }

        return false;
    }

    /**
     * @param string $event
     * @param mixed $entry
     * @param int $user_id
     *
     * @return void
     */
    public function sync_user($event, $entry, $user_id = 0)
    {
        if (apply_filters('fusewp_gravity_forms_sync_enable_spam_filter', true) && 'spam' == fusewpVar($entry, 'status')) {
            return;
        }

        $form_id = $entry['form_id'] ?? false;

        if ( ! $form_id) return;

        $user_data = $this->get_mapping_user_data($user_id, $entry);

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

                        $custom_fields = fusewpVar($destination, $sync_action::CUSTOM_FIELDS_FIELD_ID, []);

                        $gf_email_field_id = $this->get_email_gf_field_id($custom_fields);

                        if ( ! empty($gf_email_field_id) && isset($entry[$gf_email_field_id])) {

                            $email_address = $entry[$gf_email_field_id];

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
        if (class_exists('\GFAPI')) {

            $number = max(1, intval($number));
            $paged  = max(1, intval($paged));

            $offset = ($paged - 1) * $number;

            $entries = \GFAPI::get_entries(
                absint($source_item_id),
                [],
                ['key' => 'date_created', 'direction' => 'ASC'],
                ['offset' => $offset, 'page_size' => $number]
            );

            if ( ! is_wp_error($entries)) return $entries;
        }

        return [];
    }

    public function bulk_sync_handler($item)
    {
        /* the transaction_type field in an entry typically refers to the type of payment transaction associated with that entry.
        transaction_type = 1: indicates a one-time payment.
        transaction_type = 2: indicates a subscription or recurring payment.
        */
        if ($item['r']['transaction_type'] == '1' && $item['r']['payment_status'] == 'Paid') {
            $this->sync_user('after_payment', $item['r']);
        }

        if (empty($item['r']['payment_status'])) {
            $this->sync_user('form_submission', $item['r']);
        }
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