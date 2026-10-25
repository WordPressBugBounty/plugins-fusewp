<?php

namespace FuseWP\Core\Optin;

/**
 * The opt-in checkbox on registration forms of membership and profile plugins:
 * ProfilePress, Paid Member Subscriptions, UsersWP, User Registration (WPEverest),
 * Profile Builder, Simple Membership, Ultimate Member and wpForo.
 *
 * Each plugin gets a checkpoint in the "Registration Plugins" group, shown
 * only while the plugin is active. The box is printed through the plugin's own form
 * hook, just above its submit button, and names its checkpoint in a hidden field.
 *
 * All of them create the WordPress user in the request the form is sent in, so consent is
 * recorded by OptinCheckbox's `wp_pre_insert_user_data` / `user_register` hooks, which
 * read that hidden field: a sign-up that fails validation creates no user and records
 * nothing. The exceptions are handled here: User Registration posts its fields as JSON
 * (a small script adds the box's value to its request), Profile Builder's email
 * confirmation creates the user later (the tick rides along in its signup meta), and
 * Simple Membership may reuse an existing user, so it also records on its own hook.
 */
class RegistrationCheckpoints
{
    const PROFILEPRESS = 'profilepress_registration';
    const PMS = 'pms_registration';
    const USERSWP = 'userswp_registration';
    const USER_REGISTRATION = 'user_registration_form';
    const PROFILE_BUILDER = 'profile_builder_registration';
    const SIMPLE_MEMBERSHIP = 'swpm_registration';
    const ULTIMATE_MEMBER = 'ultimate_member_registration';
    const WPFORO = 'wpforo_registration';

    /** ProfilePress's checkout, listed among its forms in the Setup popup. */
    const PP_CHECKOUT_FORM = 'checkout';

    /** ProfilePress melange forms, listed as "melange-<id>". */
    const PP_MELANGE_PREFIX = 'melange-';

    /** @var bool User Registration's script is printed once per page */
    protected $ur_script = false;

    public function __construct()
    {
        add_filter('fusewp_optin_checkpoints', [$this, 'register']);

        // early on init: Simple Membership handles its registration form on init (priority 10).
        add_action('init', [$this, 'hooks'], 5);
    }

    /* ------------------------------------------------------------------
     * Registration and wiring
     * ---------------------------------------------------------------- */

    /**
     * The supported plugins, keyed by checkpoint id.
     *
     * `active` is the constant the plugin defines, so a checkpoint only exists while its
     * plugin is active; `hooks` is the method wiring its form, called only for an enabled
     * checkpoint; `forms` lists its forms in the Setup popup.
     *
     * A registered user is synced by User Roles; a plugin with a membership sync source
     * (Pro) gates that source too, so a member who didn't tick the box isn't subscribed.
     *
     * @return array
     */
    protected function integrations()
    {
        return [
            self::PROFILEPRESS      => [
                'active'  => 'PPRESS_VERSION_NUMBER',
                'label'   => esc_html__('ProfilePress Registration & Checkout', 'fusewp'),
                'sources' => ['wp_user_roles', 'profilepress'],
                'forms'   => [$this, 'profilepress_forms'],
                'hooks'   => 'profilepress_hooks',
            ],
            self::PMS               => [
                'active'  => 'PMS_VERSION',
                'label'   => esc_html__('Paid Member Subscriptions Registration', 'fusewp'),
                'sources' => ['wp_user_roles', 'paid_member_subscriptions'],
                'forms'   => null,
                'hooks'   => 'pms_hooks',
            ],
            self::USERSWP           => [
                'active'  => 'USERSWP_VERSION',
                'label'   => esc_html__('UsersWP Registration', 'fusewp'),
                'sources' => ['wp_user_roles'],
                'forms'   => [$this, 'userswp_forms'],
                'hooks'   => 'userswp_hooks',
            ],
            self::USER_REGISTRATION => [
                'active'  => 'UR_VERSION',
                'label'   => esc_html__('User Registration Forms', 'fusewp'),
                'sources' => ['wp_user_roles'],
                'forms'   => [$this, 'user_registration_forms'],
                'hooks'   => 'user_registration_hooks',
            ],
            self::PROFILE_BUILDER   => [
                'active'  => 'PROFILE_BUILDER_VERSION',
                'label'   => esc_html__('Profile Builder Registration', 'fusewp'),
                'sources' => ['wp_user_roles'],
                'forms'   => [$this, 'profile_builder_forms'],
                'hooks'   => 'profile_builder_hooks',
            ],
            self::SIMPLE_MEMBERSHIP => [
                'active'  => 'SIMPLE_WP_MEMBERSHIP_VER',
                'label'   => esc_html__('Simple Membership Registration', 'fusewp'),
                'sources' => ['wp_user_roles'],
                'forms'   => null,
                'hooks'   => 'simple_membership_hooks',
            ],
            self::ULTIMATE_MEMBER   => [
                'active'  => 'UM_VERSION',
                'label'   => esc_html__('Ultimate Member Registration', 'fusewp'),
                'sources' => ['wp_user_roles'],
                'forms'   => [$this, 'ultimate_member_forms'],
                'hooks'   => 'ultimate_member_hooks',
            ],
            self::WPFORO            => [
                'active'  => 'WPFORO_VERSION',
                'label'   => esc_html__('wpForo Registration', 'fusewp'),
                'sources' => ['wp_user_roles'],
                'forms'   => null,
                'hooks'   => 'wpforo_hooks',
            ],
        ];
    }

    /**
     * @param array $checkpoints
     *
     * @return array
     */
    public function register($checkpoints)
    {
        foreach ($this->integrations() as $id => $integration) {

            if ( ! defined($integration['active'])) continue;

            $checkpoints[$id] = [
                'label'   => $integration['label'],
                'sources' => $integration['sources'],
                'group'   => OptinCheckbox::GROUP_REGISTRATION,
                'forms'   => $integration['forms'],
            ];
        }

        return $checkpoints;
    }

    public function hooks()
    {
        // is_enabled() is only true for a registered checkpoint, so an inactive plugin is skipped.
        foreach ($this->integrations() as $id => $integration) {
            if (OptinCheckbox::is_enabled($id)) $this->{$integration['hooks']}();
        }
    }

    /**
     * The box on a registration form. Hidden from someone logged in: they aren't signing
     * up (eg. an admin adding a user on a front-end form), and it isn't their consent.
     *
     * @return string
     */
    public static function box($checkpoint_id, $args = [])
    {
        if (is_user_logged_in() && empty($_GET['pp_preview_form'])) return '';

        return OptinCheckbox::render($checkpoint_id, $args);
    }

    /* ------------------------------------------------------------------
     * ProfilePress
     * ---------------------------------------------------------------- */

    protected function profilepress_hooks()
    {
        // forms printed with the box already, by ProfilePress's own hook.
        $printed = [];

        // ProfilePress after 4.17.7: its hook just before a registration form's submit button
        // (drag & drop and shortcode-builder forms alike).
        // A melange form (ProfilePress PR #145 passes its type) is listed as "melange-<id>".
        add_action('ppress_registration_form_before_submit_button', function ($form_id, $form_type = 'registration') use (&$printed) {
            $key = 'melange' === $form_type ? self::PP_MELANGE_PREFIX . $form_id : (string)$form_id;
            $printed[$key] = true;
            $box = self::box(self::PROFILEPRESS, ['form_id' => $key, 'wrapper_tag' => 'div', 'wrapper_class' => 'pp-form-field-wrap fusewp-optin-pp']);
            if ('' === $box) return;
            // it sits in the submit button's wrapper: keep it clear of the button.
            static $styled = false;
            if ( ! $styled) {
                echo '<style>.fusewp-optin-pp{margin:0 0 16px;text-align:left}</style>';
                $styled = true;
            }
            echo $box;
        }, 10, 2);

        // the checkout, where a plan is bought (and a guest's account is created).
        add_action('ppress_checkout_before_submit_button', function () {
            echo OptinCheckbox::render(self::PROFILEPRESS, ['form_id' => self::PP_CHECKOUT_FORM, 'wrapper_tag' => 'div', 'wrapper_class' => 'ppress-checkout-form__terms_condition_wrap']);
        });

        // a logged-in buyer creates no user: record for the order's customer once the checkout
        // has passed ProfilePress's checks (nonce, validation) and created the order. Read from
        // the order, not the session, so it doesn't depend on the buyer being logged in; a
        // guest's consent, already recorded when their user was created, is kept as is.
        add_action('ppress_process_checkout_after_order_subscription_creation', function ($order_id) {
            if ( ! class_exists('\ProfilePress\Core\Membership\Models\Order\OrderFactory')) return;
            if ( ! OptinCheckbox::shows_on_form(self::PROFILEPRESS, self::PP_CHECKOUT_FORM)) return;
            $customer = \ProfilePress\Core\Membership\Models\Order\OrderFactory::fromId($order_id)->get_customer();
            $user     = $customer->exists() ? get_userdata($customer->get_user_id()) : false;
            if ($user) OptinCheckbox::maybe_record($user->user_email, self::PROFILEPRESS, $user->ID);
        });
    }

    public function profilepress_forms()
    {
        $forms = [];

        if (class_exists('\ProfilePress\Core\Classes\FormRepository')) {
            foreach ((array)\ProfilePress\Core\Classes\FormRepository::get_forms('registration') as $form) {
                $forms[(string)$form['form_id']] = $form['name'];
            }

            foreach ((array)\ProfilePress\Core\Classes\FormRepository::get_forms('melange') as $form) {
                /* translators: %s: a ProfilePress melange form's name */
                $forms[self::PP_MELANGE_PREFIX . $form['form_id']] = sprintf(esc_html__('%s (melange)', 'fusewp'), $form['name']);
            }
        }

        $forms[self::PP_CHECKOUT_FORM] = esc_html__('Checkout (buying a membership plan)', 'fusewp');

        return $forms;
    }

    /* ------------------------------------------------------------------
     * Paid Member Subscriptions
     * ---------------------------------------------------------------- */

    protected function pms_hooks()
    {
        // after the plan list, just above the Register button; only the register form.
        add_action('pms_register_form_bottom', function () {
            // also fired on the plan form shown after Profile Builder's email
            // confirmation, for an account that already exists: no box there.
            if ( ! empty($_GET['activation_key'])) return; // phpcs:ignore WordPress.Security.NonceVerification
            echo self::box(self::PMS, ['wrapper_class' => 'pms-field']);
        });
    }

    /* ------------------------------------------------------------------
     * UsersWP
     * ---------------------------------------------------------------- */

    protected function userswp_hooks()
    {
        // after the fields and UsersWP's hidden inputs, just above the submit button.
        add_action('uwp_template_fields', function ($form_type, $args = []) {
            if ('register' !== $form_type) return;
            // the form id UsersWP posts (uwp_register_form_id) defaults to 1.
            $form_id = ! empty($args['id']) ? absint($args['id']) : 1;
            echo self::box(self::USERSWP, ['form_id' => $form_id, 'wrapper_tag' => 'div', 'wrapper_class' => 'form-group mb-3']);
        }, 20, 2);
    }

    public function userswp_forms()
    {
        $forms = function_exists('uwp_get_register_forms_dropdown_options') ? (array)uwp_get_register_forms_dropdown_options() : [];

        return count($forms) < 2 ? [] : $forms;
    }

    /* ------------------------------------------------------------------
     * User Registration (WPEverest)
     * ---------------------------------------------------------------- */

    protected function user_registration_hooks()
    {
        add_action('user_registration_after_form_fields', function ($form_data, $form_id) {

            $box = self::box(self::USER_REGISTRATION, ['form_id' => $form_id, 'wrapper_tag' => 'div', 'wrapper_class' => 'ur-form-row fusewp-optin-ur']);

            if ('' === $box) return;

            echo $box;

            $this->ur_script = true;
        }, 10, 2);

        // User Registration sends only its own fields, as JSON; add the box to its request.
        add_action('wp_footer', function () {
            if ( ! $this->ur_script) return;
            ?>
            <script>
                jQuery(document).on('user_registration_frontend_before_form_submit', function (e, data, $form) {
                    var $box = jQuery($form).find('input[name="<?php echo esc_js(OptinCheckbox::FIELD); ?>"]');
                    if ( ! $box.length) return;
                    data['<?php echo esc_js(OptinCheckbox::FIELD); ?>'] = $box.is(':checked') ? '1' : '';
                    data['<?php echo esc_js(OptinCheckbox::CHECKPOINT_FIELD); ?>'] = '<?php echo esc_js(self::USER_REGISTRATION); ?>';
                });
            </script>
            <?php
        }, 99);
    }

    public function user_registration_forms()
    {
        return function_exists('ur_get_all_user_registration_form') ? (array)ur_get_all_user_registration_form() : [];
    }

    /* ------------------------------------------------------------------
     * Profile Builder
     * ---------------------------------------------------------------- */

    protected function profile_builder_hooks()
    {
        add_action('wppb_form_before_submit_button', function ($args) {
            if ('register' !== fusewpVar((array)$args, 'form_type')) return;
            echo self::box(self::PROFILE_BUILDER, ['form_id' => self::profile_builder_form_id(fusewpVar((array)$args, 'ID', '')), 'wrapper_tag' => 'span', 'wrapper_class' => 'fusewp-optin-pb']);
        });

        // the activation request creates the user (with immediate sync, its job may run right
        // away): record just before, from the signup Profile Builder kept.
        add_filter('wp_pre_insert_user_data', function ($data, $update) {
            $key = sanitize_text_field(wp_unslash($_REQUEST['activation_key'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
            if ($update || '' === $key || empty($data['user_email']) || ! OptinCheckbox::is_enabled(self::PROFILE_BUILDER)) return $data;
            global $wpdb;
            // the table Profile Builder keeps signups in, as it reads it.
            $table  = is_multisite() ? $wpdb->signups : $wpdb->base_prefix . 'signups';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
            if ( ! $exists) return $data;
            $signup = $wpdb->get_row($wpdb->prepare("SELECT user_email, meta FROM $table WHERE activation_key = %s", $key));
            $meta   = $signup ? maybe_unserialize($signup->meta) : [];
            if ($signup && 'yes' === fusewpVar((array)$meta, 'fusewp_optin') && strtolower($signup->user_email) === strtolower($data['user_email'])) {
                OptinConsent::record($data['user_email'], self::PROFILE_BUILDER);
            }
            return $data;
        }, 9, 2);

        // with Email Confirmation, the user is created later, when the link in the email is
        // opened: keep the tick in the signup, and record it then.
        add_filter('wppb_add_to_user_signup_form_meta', function ($meta) {
            if (OptinCheckbox::is_ticked_in_request() && self::PROFILE_BUILDER === OptinCheckbox::request_checkpoint()) {
                $meta['fusewp_optin'] = 'yes';
            }
            return $meta;
        });

        add_action('wppb_activate_user', function ($user_id, $password = '', $meta = []) {
            $user = get_userdata($user_id);
            if ($user && 'yes' === fusewpVar((array)$meta, 'fusewp_optin') && OptinCheckbox::is_enabled(self::PROFILE_BUILDER)) {
                OptinConsent::record($user->user_email, self::PROFILE_BUILDER, $user_id);
            }
        }, 1, 3);
    }

    public function profile_builder_forms()
    {
        $forms = wp_list_pluck(get_posts(['post_type' => 'wppb-rf-cpt', 'post_status' => 'publish', 'posts_per_page' => -1]), 'post_title', 'ID');

        // a plain [wppb-register] whose default form isn't a form post.
        if ('default' === self::profile_builder_form_id('')) {
            $forms = ['default' => esc_html__('Default registration form', 'fusewp')] + $forms;
        }

        // the free version has its one default form: nothing to pick.
        return count($forms) < 2 ? [] : $forms;
    }

    /**
     * A Profile Builder form's id for the Setup popup: its form post, or "default" for a
     * plain [wppb-register] that names none.
     */
    public static function profile_builder_form_id($id)
    {
        if ( ! empty($id)) return (string)$id;

        $defaults = (array)get_option('wppb_default_form_ids', []);

        return ! empty($defaults['register']) ? (string)$defaults['register'] : 'default';
    }

    /* ------------------------------------------------------------------
     * Simple Membership
     * ---------------------------------------------------------------- */

    protected function simple_membership_hooks()
    {
        // a filter whose return value Simple Membership prints above its submit button.
        add_filter('swpm_before_registration_submit_button', function ($html) {
            return $html . self::box(self::SIMPLE_MEMBERSHIP, ['wrapper_tag' => 'div', 'wrapper_class' => 'swpm-form-row']);
        });

        // the WordPress user is created in this request, unless one with that email already
        // existed (Simple Membership then reuses it): record on its own hook as well.
        add_action('swpm_front_end_registration_complete_user_data', function ($member_info) {
            $email = (string)fusewpVar((array)$member_info, 'email', '');
            $user  = $email ? get_user_by('email', $email) : false;
            OptinCheckbox::maybe_record($email, self::SIMPLE_MEMBERSHIP, $user ? $user->ID : 0);
        });
    }

    /* ------------------------------------------------------------------
     * Ultimate Member
     * ---------------------------------------------------------------- */

    protected function ultimate_member_hooks()
    {
        // fired by register forms only (login and profile forms have their own hooks);
        // UM prints its submit button on it at priority 1000.
        add_action('um_after_register_fields', function ($args, $form_id = '') {
            // the form id is the hook's second argument since UM 2.14; before, it's in $args.
            if ('' === (string)$form_id) $form_id = fusewpVar((array)$args, 'form_id', '');
            echo self::box(self::ULTIMATE_MEMBER, ['form_id' => (string)$form_id, 'wrapper_tag' => 'div', 'wrapper_class' => 'um-field fusewp-optin-um']);
        }, 900, 2);
    }

    public function ultimate_member_forms()
    {
        $forms = wp_list_pluck(get_posts([
            'post_type'      => 'um_form',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_key'       => '_um_mode',
            'meta_value'     => 'register',
        ]), 'post_title', 'ID');

        return count($forms) < 2 ? [] : $forms;
    }

    /* ------------------------------------------------------------------
     * wpForo
     * ---------------------------------------------------------------- */

    protected function wpforo_hooks()
    {
        // wpForo's own sign-up form fires `register_form` just above its submit button:
        // its box takes the place of the WordPress registration form's there.
        add_filter('fusewp_optin_register_form_box', function ($html) {
            if (is_string($html) || ! function_exists('WPF')) return $html;
            $object = (array)(WPF()->current_object ?? []);
            if ('register' !== fusewpVar($object, 'template')) return $html;
            return self::box(self::WPFORO, ['wrapper_tag' => 'div', 'wrapper_class' => 'wpforo-legal-checkbox fusewp-optin-wpforo']);
        });
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
