<?php

namespace FuseWP\Core\Optin;

/**
 * The opt-in checkbox on the sign-up and checkout forms of LMS plugins: LifterLMS,
 * Tutor LMS and Academy LMS.
 *
 * Each is a checkpoint in the "Registration Plugins" group, with its forms (registration,
 * checkout, ...) in the Setup popup. A sign-up creates the WordPress user in the request
 * the form is sent in, so consent is recorded by OptinCheckbox's user-creation hooks, which
 * read the box's hidden checkpoint field (see RegistrationCheckpoints). A logged-in buyer
 * creates no user: their tick is recorded on the plugin's own hook once the order exists.
 *
 * Each gates User Roles (the new account) and the plugin's course sync sources (Pro).
 */
class LmsCheckpoints
{
    const LIFTERLMS = 'lifterlms_registration';
    const TUTOR = 'tutor_lms_registration';
    const ACADEMY = 'academy_lms_registration';

    public function __construct()
    {
        add_filter('fusewp_optin_checkpoints', [$this, 'register']);

        // LifterLMS and Tutor handle their forms on init / template_redirect.
        add_action('init', [$this, 'hooks'], 5);

        // LifterLMS's AJAX checkout creates its order on init at priority 5 too: listen from the start.
        add_action('lifterlms_new_pending_order', [$this, 'lifterlms_order']);
    }

    /**
     * A LifterLMS order exists once the checkout has passed LifterLMS's checks.
     */
    public function lifterlms_order($order)
    {
        if ( ! OptinCheckbox::is_enabled(self::LIFTERLMS) || ! is_object($order) || ! method_exists($order, 'get')) return;

        $form = 'free_enroll' === sanitize_key(wp_unslash($_POST['form'] ?? '')) ? 'free_enroll' : 'checkout'; // phpcs:ignore WordPress.Security.NonceVerification

        self::record_buyer(self::LIFTERLMS, $form, absint($order->get('user_id')));
    }

    protected function integrations()
    {
        return [
            self::LIFTERLMS => [
                'active'  => 'LLMS_VERSION',
                'label'   => esc_html__('LifterLMS Registration & Checkout', 'fusewp'),
                'sources' => ['wp_user_roles', 'lifterlms_course', 'lifterlms_membership'],
                'forms'   => [$this, 'lifterlms_forms'],
                'hooks'   => 'lifterlms_hooks',
            ],
            self::TUTOR     => [
                'active'  => 'TUTOR_VERSION',
                'label'   => esc_html__('Tutor LMS Registration & Checkout', 'fusewp'),
                'sources' => ['wp_user_roles', 'tutorlms_course', 'tutorlms_course_category', 'tutorlms_course_tag'],
                'forms'   => [$this, 'tutor_forms'],
                'hooks'   => 'tutor_hooks',
            ],
            self::ACADEMY   => [
                'active'  => 'ACADEMY_VERSION',
                'label'   => esc_html__('Academy LMS Registration', 'fusewp'),
                'sources' => ['wp_user_roles', 'academylms_course', 'academylms_course_category', 'academylms_course_tag'],
                'forms'   => [$this, 'academy_forms'],
                'hooks'   => 'academy_hooks',
            ],
        ];
    }

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
        foreach ($this->integrations() as $id => $integration) {
            if (OptinCheckbox::is_enabled($id)) $this->{$integration['hooks']}();
        }
    }

    /**
     * A checkout box: shown to a logged-in buyer too, since they buy for themselves.
     */
    protected static function checkout_box($checkpoint_id, $form_id, $args = [])
    {
        return OptinCheckbox::render($checkpoint_id, array_merge($args, ['form_id' => $form_id]));
    }

    /**
     * A logged-in buyer's tick, once the plugin has created their order: no user is created
     * for them, so the user-creation hooks don't record it.
     */
    protected static function record_buyer($checkpoint_id, $form_id, $user_id)
    {
        $user = $user_id ? get_userdata($user_id) : false;

        if ( ! $user || ! OptinCheckbox::shows_on_form($checkpoint_id, $form_id)) return;

        // the box this request posted belongs to this checkpoint.
        if ($checkpoint_id !== sanitize_key(wp_unslash($_POST[OptinCheckbox::CHECKPOINT_FIELD] ?? ''))) return; // phpcs:ignore WordPress.Security.NonceVerification

        OptinCheckbox::maybe_record($user->user_email, $checkpoint_id, $user->ID);
    }

    /* ------------------------------------------------------------------
     * LifterLMS
     * ---------------------------------------------------------------- */

    /**
     * LifterLMS styles every form label as a bold block: keep the box next to its text.
     */
    protected static function lifterlms_box($html)
    {
        static $styled = false;

        if ('' === $html || $styled) return $html;

        $styled = true;

        return '<style>.llms-form-field.fusewp-optin-llms label{display:flex;align-items:center;gap:8px;font-weight:400}' .
               '.llms-form-field.fusewp-optin-llms label span{font-weight:400}' .
               '.llms-form-field.fusewp-optin-llms input[type=checkbox]{width:auto;flex:0 0 auto;margin:0}</style>' . $html;
    }

    protected function lifterlms_hooks()
    {
        // [lifterlms_registration] / the account page's sign-up form, above its button.
        add_action('lifterlms_before_registration_button', function () {
            echo self::lifterlms_box(RegistrationCheckpoints::box(self::LIFTERLMS, ['form_id' => 'registration', 'wrapper_tag' => 'div', 'wrapper_class' => 'llms-form-field llms-cols-12 fusewp-optin-llms']));
        });

        // the checkout (free and paid plans), above the terms and the button. A guest's
        // account is created when it's sent.
        add_action('llms_checkout_footer_before', function () {
            echo self::lifterlms_box(self::checkout_box(self::LIFTERLMS, 'checkout', ['wrapper_tag' => 'div', 'wrapper_class' => 'llms-form-field llms-cols-12 fusewp-optin-llms']));
        });

        // the one-click enrolment form for a free plan, shown to logged-in students.
        add_action('lifterlms_after_free_enroll_fields', function () {
            echo self::lifterlms_box(self::checkout_box(self::LIFTERLMS, 'free_enroll', ['wrapper_tag' => 'div', 'wrapper_class' => 'llms-form-field fusewp-optin-llms']));
        });

    }

    public function lifterlms_forms()
    {
        return [
            'registration' => esc_html__('Registration form', 'fusewp'),
            'checkout'     => esc_html__('Checkout', 'fusewp'),
            'free_enroll'  => esc_html__('Free enrollment button (logged-in students)', 'fusewp'),
        ];
    }

    /* ------------------------------------------------------------------
     * Tutor LMS
     * ---------------------------------------------------------------- */

    protected function tutor_hooks()
    {
        // Tutor's student and instructor sign-up forms fire `register_form` after their
        // fields: Tutor's box takes the place of the WordPress registration form's there.
        add_filter('fusewp_optin_register_form_box', function ($html) {
            if (is_string($html)) return $html;
            foreach (['student', 'instructor'] as $form) {
                // inside that form: its middle fields printed, its end not yet.
                if (did_action("tutor_{$form}_reg_form_middle") > did_action("tutor_{$form}_reg_form_end")) {
                    return RegistrationCheckpoints::box(self::TUTOR, ['form_id' => $form, 'wrapper_tag' => 'div', 'wrapper_class' => 'tutor-form-row tutor-mb-16']);
                }
            }
            return $html;
        });

        // Tutor's own checkout has no action near its button; this filter is applied on the
        // line before it, so the box printed here sits just above the button. Its value is
        // passed through untouched.
        add_filter('tutor_checkout_enable_pay_now_btn', function ($enable) {
            echo self::checkout_box(self::TUTOR, 'checkout', ['wrapper_tag' => 'div', 'wrapper_class' => 'tutor-mt-16 tutor-fs-7 tutor-color-secondary']);
            return $enable;
        }, 1);

        // the order exists once the checkout has passed Tutor's checks.
        add_action('tutor_after_checkout_consent', function ($user_id) {
            self::record_buyer(self::TUTOR, 'checkout', absint($user_id));
        });
    }

    public function tutor_forms()
    {
        return [
            'student'    => esc_html__('Student registration', 'fusewp'),
            'instructor' => esc_html__('Instructor registration', 'fusewp'),
            'checkout'   => esc_html__('Checkout', 'fusewp'),
        ];
    }

    /* ------------------------------------------------------------------
     * Academy LMS
     * ---------------------------------------------------------------- */

    protected function academy_hooks()
    {
        foreach (['student', 'instructor'] as $role) {
            // the shortcode forms and the Registration Form block, just above the button.
            add_action("academy/templates/{$role}_reg_form_before_submit", function () use ($role) {
                echo RegistrationCheckpoints::box(self::ACADEMY, ['form_id' => $role, 'wrapper_tag' => 'div', 'wrapper_class' => 'academy-form-group']);
            });
        }

        // Academy posts the form as JSON to its REST route, so the box isn't in $_POST:
        // hand the tick over before the route creates the user.
        add_filter('rest_request_before_callbacks', function ($response, $handler, $request) {

            if ( ! $request instanceof \WP_REST_Request || '/academy/v1/register' !== $request->get_route()) return $response;

            $role = sanitize_key((string)$request->get_param('role'));

            if (
                self::ACADEMY === sanitize_key((string)$request->get_param(OptinCheckbox::CHECKPOINT_FIELD)) &&
                OptinGate::is_truthy($request->get_param(OptinCheckbox::FIELD)) &&
                OptinCheckbox::shows_on_form(self::ACADEMY, $role)
            ) {
                OptinCheckbox::set_ticked(self::ACADEMY);
            }

            return $response;
        }, 10, 3);
    }

    public function academy_forms()
    {
        return [
            'student'    => esc_html__('Student registration', 'fusewp'),
            'instructor' => esc_html__('Instructor registration', 'fusewp'),
        ];
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
