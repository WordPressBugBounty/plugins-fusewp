<?php

namespace FuseWP\Core\Optin;

/**
 * The opt-in checkbox on GiveWP donation forms and the WP Travel Engine checkout.
 *
 * As with WooCommerce, the tick is first kept on the donation or booking (`_fusewp_optin`)
 * and covers that donation's or booking's own syncs (see has_consent()); it becomes the
 * contact's consent once the donation or booking is paid, so an unpaid one made with
 * someone else's email records nothing for them.
 */
class PaymentCheckpoints
{
    const GIVEWP = 'givewp_donation';
    const WP_TRAVEL_ENGINE = 'wp_travel_engine_checkout';

    const META = '_fusewp_optin';

    /** a v3 (visual builder) GiveWP form's field; GiveWP stores its value under META. */
    const GIVEWP_FIELD = 'fusewpOptin';

    public function __construct()
    {
        add_filter('fusewp_optin_checkpoints', [$this, 'register']);

        add_filter('fusewp_optin_has_consent', [$this, 'has_consent'], 10, 2);

        // erased consent: the ticks kept on that person's donations and bookings go too, or
        // their next sync would let them back in.
        add_action('fusewp_optin_consent_withdrawn', [$this, 'clear_flags']);

        add_action('init', [$this, 'hooks'], 5);
    }

    public function register($checkpoints)
    {
        if (defined('GIVE_VERSION')) {
            $checkpoints[self::GIVEWP] = [
                'label'   => esc_html__('GiveWP Donation Forms', 'fusewp'),
                'sources' => ['givewp_donations', 'givewp_subscriptions'],
            ];
        }

        if (defined('WP_TRAVEL_ENGINE_VERSION')) {
            $checkpoints[self::WP_TRAVEL_ENGINE] = [
                'label'   => esc_html__('WP Travel Engine Checkout', 'fusewp'),
                'sources' => ['wp_travel_engine_trips', 'wp_travel_engine_trip_types'],
            ];
        }

        return $checkpoints;
    }

    public function hooks()
    {
        if (OptinCheckbox::is_enabled(self::GIVEWP)) $this->givewp_hooks();

        if (OptinCheckbox::is_enabled(self::WP_TRAVEL_ENGINE)) $this->wp_travel_engine_hooks();
    }

    /**
     * Whether the box shows: not to a logged-in visitor who already consented.
     */
    protected static function shows()
    {
        return ! (is_user_logged_in() && OptinConsent::has('', get_current_user_id()));
    }

    /**
     * A donation's or booking's own syncs go ahead when it carries the tick; one made
     * before opt-in was required is grandfathered by its date.
     *
     * @param bool $has_consent
     * @param array $item queue job
     *
     * @return bool
     */
    public function has_consent($has_consent, $item)
    {
        if ($has_consent) return $has_consent;

        $extras = $item['extras'] ?? null;
        $source = (string)($item['source_id'] ?? '');

        $id = $date = 0;

        if (in_array($source, ['givewp_donations', 'givewp_subscriptions'], true)) {

            // a subscription takes its first donation's tick.
            if (is_a($extras, '\Give_Subscription')) {
                $id = absint($extras->parent_payment_id ?? 0);
            } elseif (is_a($extras, '\Give_Payment')) {
                $id = absint($extras->ID ?? 0);
            }

            if ( ! $id) return $has_consent;

            if (OptinGate::is_truthy(give_get_meta($id, self::META, true))) return true;

            // a pending post has no GMT date yet: get_post_timestamp() falls back to its local date.
            $date = (int)get_post_timestamp($id);

        } elseif (in_array($source, ['wp_travel_engine_trips', 'wp_travel_engine_trip_types'], true) && is_array($extras)) {

            $id = absint($extras['id'] ?? 0);

            if ( ! $id) return $has_consent;

            if (OptinGate::is_truthy(get_post_meta($id, self::META, true))) return true;

            // a pending post has no GMT date yet: get_post_timestamp() falls back to its local date.
            $date = (int)get_post_timestamp($id);

        } else {
            return $has_consent;
        }

        return OptinCheckbox::grandfather_existing() && $date && $date < OptinCheckbox::enabled_at();
    }

    /* ------------------------------------------------------------------
     * GiveWP
     * ---------------------------------------------------------------- */

    protected function givewp_hooks()
    {
        // v3 (visual builder) forms: a checkbox field at the end of the form, kept as the
        // donation's META by GiveWP itself.
        add_action('givewp_donation_form_schema', function ($form) {

            if ( ! is_object($form) || ! method_exists($form, 'all') || ! self::shows()) return;
            if ( ! class_exists('\Give\Framework\FieldsAPI\Checkbox') || ! is_null($form->getNodeByName(self::GIVEWP_FIELD))) return;

            $sections = $form->all();
            $last     = $sections ? end($sections) : null;

            if ( ! $last || ! method_exists($last, 'append')) return;

            $last->append(
                \Give\Framework\FieldsAPI\Checkbox::make(self::GIVEWP_FIELD)
                    ->label(wp_strip_all_tags(OptinCheckbox::label(self::GIVEWP)))
                    ->value('1')
                    ->checked(OptinCheckbox::is_prechecked())
                    ->metaKey(self::META)
                    ->showInAdmin(false)
                    ->showInReceipt(false)
            );
        });

        // legacy (v2) forms: just above the Donate button.
        add_action('give_donation_form_before_submit', function ($form_id) {
            echo OptinCheckbox::render(self::GIVEWP, ['wrapper_class' => 'form-row form-row-wide give-fusewp-optin']);
        }, 1000);

        // a legacy form's donation is created in the request its form is sent in.
        add_action('give_insert_payment', function ($payment_id) {
            if ( ! empty($_POST[OptinCheckbox::FIELD])) { // phpcs:ignore WordPress.Security.NonceVerification
                give_update_meta($payment_id, self::META, 'yes');
            }
        }, 1);

        // paid: the donor's consent, before FuseWP's own sync (priority 9999) runs.
        add_action('give_update_payment_status', function ($payment_id, $status) {

            if ('publish' !== $status || ! OptinGate::is_truthy(give_get_meta($payment_id, self::META, true))) return;

            $payment = new \Give_Payment($payment_id);

            OptinConsent::record((string)$payment->email, self::GIVEWP, absint($payment->user_id));
        }, 1, 2);
    }

    /* ------------------------------------------------------------------
     * WP Travel Engine
     * ---------------------------------------------------------------- */

    protected function wp_travel_engine_hooks()
    {
        // next to the checkout's terms checkbox, above the Book button. The button's own area
        // is redrawn over AJAX (cart and payment changes), which would untick a box there.
        add_filter('wte_booking_privacy_fields', function ($fields) {

            if ( ! self::shows()) return $fields;

            $fields['fusewp_optin'] = [
                'type'          => 'checkbox',
                'options'       => ['1' => wp_kses_post(OptinCheckbox::label(self::WP_TRAVEL_ENGINE))],
                'name'          => OptinCheckbox::FIELD,
                'id'            => OptinCheckbox::FIELD,
                'wrapper_class' => 'wpte-checkout__form-control fusewp-optin-checkbox',
                // an array: WP Travel Engine compares a string default strictly with its (integer) option key.
                'default'       => OptinCheckbox::is_prechecked() ? ['1'] : [],
                'priority'      => 80,
            ];

            return (array)$fields;
        });

        // the booking exists, its form is in $_POST; FuseWP's first sync of it is queued on
        // this hook too, so keep the tick before that.
        add_action('wte_after_booking_created', function ($booking_id) {
            if (OptinCheckbox::is_ticked_in_request()) update_post_meta($booking_id, self::META, 'yes');
        }, 1);

        // paid: the booking's payment status is one WP Travel Engine counts as paid ("completed"
        // when a gateway or a zero total settles it; a deposit, a received check, ...).
        $paid = function ($meta_id, $booking_id, $meta_key, $value) {

            if ('wp_travel_engine_booking_payment_status' !== $meta_key) return;

            $paid_statuses = function_exists('wptravelengine_success_payment_status') ? array_keys((array)wptravelengine_success_payment_status()) : ['completed'];

            if ( ! in_array((string)$value, $paid_statuses, true)) return;
            if ('booking' !== get_post_type($booking_id) || ! OptinGate::is_truthy(get_post_meta($booking_id, self::META, true))) return;
            if ( ! function_exists('wptravelengine_get_booking')) return;

            $booking = wptravelengine_get_booking($booking_id);
            $email   = $booking ? (string)$booking->get_billing_email() : '';
            $user    = $email ? get_user_by('email', $email) : false;

            OptinConsent::record($email, self::WP_TRAVEL_ENGINE, $user ? $user->ID : 0);
        };

        add_action('added_post_meta', $paid, 10, 4);
        add_action('updated_post_meta', $paid, 10, 4);
    }

    /**
     * @param string $email whose consent was erased
     */
    public function clear_flags($email)
    {
        global $wpdb;

        $email = strtolower(trim((string)$email));

        if ( ! is_email($email)) return;

        // GiveWP donations, by the donor email GiveWP keeps on each.
        $table = $wpdb->prefix . 'give_donationmeta';

        if (defined('GIVE_VERSION') && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT donation_id FROM $table WHERE meta_key = '_give_payment_donor_email' AND LOWER(meta_value) = %s", $email));
            foreach ($ids as $id) give_delete_meta((int)$id, self::META);
        }

        // WP Travel Engine bookings, by their billing email (kept in serialized billing meta).
        if (function_exists('wptravelengine_get_booking')) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key IN ('wptravelengine_billing_details', 'billing_info') AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($email) . '%'
            ));
            foreach ($ids as $id) {
                $booking = wptravelengine_get_booking((int)$id);
                if ($booking && strtolower((string)$booking->get_billing_email()) === $email) delete_post_meta((int)$id, self::META);
            }
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
