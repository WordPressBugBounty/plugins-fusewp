<?php

namespace FuseWP\Core\Optin;

use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

/**
 * Stops a queued subscribe job for a contact who has not consented.
 *
 * Every sync job, live or bulk, core or Pro, passes through SyncQueueHandler,
 * so this is the one place consent is enforced. Unsubscribe jobs always pass.
 *
 * Form sources work differently: a sync rule can map a form field to
 * "Opt-in Consent", and the entry only syncs when that field has a value.
 */
class OptinGate
{
    const CONSENT_FIELD_ID = 'fusewpConsent';

    public function __construct()
    {
        add_filter('fusewp_sync_queue_should_process', [$this, 'gate'], 10, 2);

        add_filter('fusewp_fieldmap_integration_contact_fields', [$this, 'add_consent_field_mapping_ui'], 20);
    }

    /**
     * @param bool $should_process
     * @param array $item queue job
     *
     * @return bool
     */
    public function gate($should_process, $item)
    {
        if ( ! $should_process || fusewpVar($item, 'action') != 'subscribe_user') return $should_process;

        $source_id = fusewpVar($item, 'source_id', '');
        $email     = fusewpVar($item, 'email_address', '');
        $entity    = fusewpVar($item, 'mappingUserDataEntity');
        $user_id   = $this->user_id($entity, $email);

        $form_consent = self::form_consent_value($item);

        if ( ! is_null($form_consent)) {

            if ( ! self::is_truthy($form_consent)) {
                return $this->skip($item, 'form_consent_field_empty');
            }

            OptinConsent::record($email, $source_id, $user_id);

            return true;
        }

        if ( ! OptinCheckbox::is_required($source_id)) return true;

        // a job processed in the very request the box was ticked in (immediate sync) is the
        // contact who ticked it, when that box's checkpoint gates this source; cron and
        // bulk-sync requests carry no such POST field.
        $ticked_now = ! wp_doing_cron() && OptinCheckbox::is_ticked_for_source($source_id);

        $has_consent = $ticked_now || OptinConsent::has($email, $user_id) || $this->is_grandfathered($user_id);

        $has_consent = apply_filters('fusewp_optin_has_consent', $has_consent, $item, $user_id);

        return $has_consent ? true : $this->skip($item, 'no_consent');
    }

    protected function skip($item, $reason)
    {
        do_action('fusewp_optin_sync_skipped', $item, $reason);

        return false;
    }

    protected function user_id($entity, $email)
    {
        if ($entity instanceof MappingUserDataEntity && ($id = absint($entity->get('user_id')))) {
            return $id;
        }

        $user = $email ? get_user_by('email', $email) : false;

        return $user ? $user->ID : 0;
    }

    protected function is_grandfathered($user_id)
    {
        if ( ! $user_id || ! OptinCheckbox::grandfather_existing()) return false;

        $user = get_userdata($user_id);

        return $user && strtotime($user->user_registered . ' UTC') < OptinCheckbox::enabled_at();
    }

    /**
     * Whether a mapped form field means "consented". Meant for checkbox fields: empty
     * is no, any checked value is yes. Radio or select fields with a "No" option can
     * be handled with the `fusewp_optin_form_consent_is_given` filter.
     */
    public static function is_truthy($value)
    {
        $raw = $value;

        if (is_array($value)) $value = implode('', array_filter($value));

        $value = strtolower(trim((string)$value));

        $is_given = $value !== '' && ! in_array($value, ['0', 'no', 'false', 'off', 'n'], true);

        return (bool)apply_filters('fusewp_optin_form_consent_is_given', $is_given, $raw);
    }

    /**
     * Value of the form field mapped to "Opt-in Consent", or null when the rule maps none.
     *
     * @param array $item
     *
     * @return mixed|null
     */
    public static function form_consent_value($item)
    {
        $integration_id = fusewpVar($item, 'integration', '');

        // without an id, the function returns every integration.
        $integration = $integration_id ? fusewp_get_registered_sync_integrations($integration_id) : null;

        if ( ! is_object($integration)) return null;

        $sync_action   = $integration->get_sync_action();
        $custom_fields = fusewpVar(fusewpVar($item, 'destination', []), $sync_action::CUSTOM_FIELDS_FIELD_ID, []);

        $index = array_search(self::CONSENT_FIELD_ID, (array)fusewpVar($custom_fields, 'field_values', []), true);

        if (false === $index) return null;

        $form_field = $custom_fields['mappable_data'][$index] ?? '';

        if (empty($form_field)) return null;

        $entity = fusewpVar($item, 'mappingUserDataEntity');

        return $entity instanceof MappingUserDataEntity ? $entity->get($form_field) : '';
    }

    public function add_consent_field_mapping_ui($integration_contact_fields)
    {
        $source = fusewpVarPOST('source', $GLOBALS['fusewp_sync_rule_source_id'] ?? '', true);

        if ( ! in_array($source, OptinCheckbox::FORM_SOURCES, true) || ! is_array($integration_contact_fields)) {
            return $integration_contact_fields;
        }

        $position = 0;

        foreach (array_values($integration_contact_fields) as $index => $field) {
            if ($field->id == self::CONSENT_FIELD_ID) return $integration_contact_fields;
            // Place consent right after the Lead Email Address field the form sources prepend.
            if ($field->id == 'fusewpEmail') $position = $index + 1;
        }

        $consent_field = (new ContactFieldEntity())
            ->set_id(self::CONSENT_FIELD_ID)
            ->set_name(esc_html__('Opt-in Consent', 'fusewp'));

        array_splice($integration_contact_fields, $position, 0, [$consent_field]);

        return $integration_contact_fields;
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
