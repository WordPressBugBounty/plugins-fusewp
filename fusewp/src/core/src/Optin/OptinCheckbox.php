<?php

namespace FuseWP\Core\Optin;

/**
 * The opt-in checkbox: its settings, the places it can be shown ("checkpoints")
 * and which sync sources then require consent before subscribing anyone.
 *
 * The site owner picks integrations in one list. A checkpoint (eg. WooCommerce
 * checkout) shows the box and gates its sources; a form source is gated by the
 * consent field mapped in its sync rule; any other source (eg. an LMS) is gated
 * by consent recorded elsewhere.
 *
 * A checkpoint is where the box is rendered and the tick recorded, eg. the
 * WooCommerce checkout. It names the sync sources it gates. Pro registers its
 * checkpoints through the `fusewp_optin_checkpoints` filter.
 */
class OptinCheckbox
{
    const FIELD = 'fusewp_optin';

    const ENABLED_AT_OPTION = 'fusewp_optin_enabled_at';

    /** names the checkpoint a box was rendered for, so a user created in that request records under it. */
    const CHECKPOINT_FIELD = 'fusewp_optin_checkpoint';

    /** checkpoints in this group are registration forms of other plugins (see RegistrationCheckpoints). */
    const GROUP_REGISTRATION = 'registration';

    /** @var bool a plugin that posts its fields as JSON tells us about the tick, see set_ticked() */
    protected static $ticked = false;

    /** @var string the checkpoint set_ticked() named */
    protected static $ticked_checkpoint = '';

    /** Form sources map a consent field per sync rule instead, see OptinGate. */
    const FORM_SOURCES = [
        'contact_forms_7', 'everestforms', 'fluentforms', 'formidable', 'forminator',
        'gravity_forms', 'ninja_forms', 'sure_forms', 'wpforms', 'wsform'
    ];

    public function __construct()
    {
        add_filter('fusewp_settings_page', [$this, 'settings'], 5);

        // read the saved value from the hook, not fusewp_get_settings(): that is cached per
        // request and may already hold the settings as they were before this save.
        add_action('add_option_' . FUSEWP_SETTINGS_DB_OPTION_NAME, function ($option, $value) {
            $this->track_enabled_at($value);
        }, 10, 2);
        add_action('update_option_' . FUSEWP_SETTINGS_DB_OPTION_NAME, function ($old_value, $value) {
            $this->track_enabled_at($value);
        }, 10, 2);

        // WordPress core registration form. Tutor LMS and other plugins that call
        // `register_form` on their own sign-up forms get the box too.
        // A plugin whose own sign-up form fires `register_form` (wpForo, Tutor LMS) and has a
        // checkpoint of its own returns its box instead, so the form shows one box, not two.
        add_action('register_form', function () {
            $html = apply_filters('fusewp_optin_register_form_box', null);
            echo is_string($html) ? $html : self::render('wp_registration');
        });

        // record before the user is inserted: inserting fires `set_user_role`, which can queue
        // (and with immediate sync, run) a subscribe job before `user_register` fires.
        // A registration plugin's form (ProfilePress, UsersWP, ...) creates its user the same
        // way; the box it showed names its checkpoint, see request_checkpoint().
        // Someone logged in creating an account (an admin on a front-end form) is not the
        // person who consents.
        add_filter('wp_pre_insert_user_data', function ($data, $update) {
            if ( ! $update && ! empty($data['user_email']) && ! is_user_logged_in()) self::maybe_record($data['user_email'], self::request_checkpoint());
            return $data;
        }, 10, 2);

        // then attach the new user ID to that consent.
        add_action('user_register', function ($user_id) {
            $user = get_userdata($user_id);
            if ($user && ! is_user_logged_in()) self::maybe_record($user->user_email, self::request_checkpoint(), $user_id);
        }, 1);

        add_action('admin_enqueue_scripts', [$this, 'setup_assets']);

        // the Setup popups only exist for active plugins: a save keeps the others' settings.
        add_filter('wp_cspa_santized_data', function ($data, $option_name) {
            if (FUSEWP_SETTINGS_DB_OPTION_NAME !== $option_name || ! is_array($data) || ! array_key_exists('optin_setup', $data)) return $data;
            $stored = (array)fusewpVar((array)get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []), 'optin_setup', []);
            $data['optin_setup'] = array_merge(array_diff_key($stored, self::checkpoints()), (array)$data['optin_setup']);
            return $data;
        }, 10, 2);
    }

    /**
     * The checkpoint whose box was submitted in this request, when a user created in it should
     * record consent: the one the rendered box named, when it is the WordPress registration form
     * or a registration checkpoint that is switched on. Anything else, eg. a checkout box (GiveWP,
     * WP Travel Engine) whose plugin creates an account on the way, records nothing here: those
     * record once paid.
     *
     * @return string empty when none.
     */
    public static function request_checkpoint()
    {
        if ('' !== self::$ticked_checkpoint && self::is_enabled(self::$ticked_checkpoint)) return self::$ticked_checkpoint;

        $posted = self::posted_checkpoint();

        if ('wp_registration' === $posted) return $posted;

        if ('' !== $posted && self::is_enabled($posted) && self::GROUP_REGISTRATION === self::checkpoint_group($posted)) {
            return $posted;
        }

        return '';
    }

    /**
     * The checkpoint the box ticked in this request belongs to: set_ticked()'s, or the box's
     * hidden field. Empty when there is none.
     *
     * @return string
     */
    public static function posted_checkpoint()
    {
        if ('' !== self::$ticked_checkpoint) return self::$ticked_checkpoint;

        return sanitize_key(wp_unslash($_POST[self::CHECKPOINT_FIELD] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Whether a box ticked in this request gates this sync source: the tick of one checkpoint
     * (eg. a donation form) doesn't stand for another's (eg. the account it created).
     */
    public static function is_ticked_for_source($source_id)
    {
        if ( ! self::is_ticked_in_request()) return false;

        $checkpoint = self::posted_checkpoint();

        return '' !== $checkpoint && in_array($source_id, (array)fusewpVar(fusewpVar(self::checkpoints(), $checkpoint, []), 'sources', []), true);
    }

    public static function checkpoint_group($checkpoint_id)
    {
        return (string)fusewpVar(fusewpVar(self::checkpoints(), $checkpoint_id, []), 'group', '');
    }

    /**
     * @return array [checkpoint_id => ['label' => string, 'sources' => string[]]]
     */
    public static function checkpoints()
    {
        return apply_filters('fusewp_optin_checkpoints', [
            'wp_registration' => [
                'label'   => esc_html__('WordPress Registration Form', 'fusewp'),
                'sources' => ['wp_user_roles'],
            ]
        ]);
    }

    /**
     * Integrations the site owner picked in "Require Opt-in Consent For":
     * checkpoint IDs (eg. woocommerce_checkout) and sync source IDs (eg. wpforms).
     *
     * @return string[]
     */
    public static function selected_integrations()
    {
        return array_values(array_filter((array)fusewp_get_settings('optin_integrations')));
    }

    public static function enabled_checkpoints()
    {
        return array_values(array_intersect(self::selected_integrations(), array_keys(self::checkpoints())));
    }

    /**
     * Options for the integrations multi-select, grouped by how consent is collected.
     *
     * @return array
     */
    public static function integration_options()
    {
        $checkpoints = self::checkpoints();

        $covered = [];
        foreach ($checkpoints as $checkpoint) {
            $covered = array_merge($covered, (array)fusewpVar($checkpoint, 'sources', []));
        }

        $forms = $others = [];

        // a source a checkpoint now covers stays listed while it's selected on its own (saved
        // before the checkpoint existed), so it keeps showing, and a save doesn't drop it.
        $selected = self::selected_integrations();

        foreach (fusewp_get_registered_sync_sources() as $source) {

            if ( ! isset($source->id, $source->title)) continue;

            if (in_array($source->id, $covered, true) && ! in_array($source->id, $selected, true)) continue;

            if (in_array($source->id, self::FORM_SOURCES, true)) {
                $forms[$source->id] = $source->title;
            } else {
                $others[$source->id] = $source->title;
            }
        }

        asort($forms);
        asort($others);

        $registration = array_filter($checkpoints, function ($checkpoint) {
            return self::GROUP_REGISTRATION === fusewpVar($checkpoint, 'group');
        });

        return array_filter([
            esc_html__('Checkout & WordPress Registration: adds an opt-in checkbox', 'fusewp') => wp_list_pluck(array_diff_key($checkpoints, $registration), 'label'),
            esc_html__('Registration Plugins: adds an opt-in checkbox', 'fusewp')              => wp_list_pluck($registration, 'label'),
            esc_html__('Forms: map a form field to "Opt-in Consent"', 'fusewp')                => $forms,
            esc_html__('Others: uses the consent given at checkout or registration', 'fusewp') => $others,
        ]);
    }

    public static function is_enabled($checkpoint_id)
    {
        return in_array($checkpoint_id, self::enabled_checkpoints(), true);
    }

    /**
     * Sync sources that only subscribe a contact who has consented.
     *
     * @return string[]
     */
    public static function required_sources()
    {
        $checkpoints = self::checkpoints();

        // selected sync sources (forms, LMS, ...) plus the sources each selected checkpoint gates.
        $sources = array_diff(self::selected_integrations(), array_keys($checkpoints));

        foreach (self::enabled_checkpoints() as $checkpoint_id) {
            $sources = array_merge($sources, (array)fusewpVar($checkpoints[$checkpoint_id], 'sources', []));
        }

        return array_values(array_unique(apply_filters('fusewp_optin_required_sources', $sources)));
    }

    public static function is_required($source_id)
    {
        return in_array($source_id, self::required_sources(), true);
    }

    /**
     * @param string $checkpoint_id a checkpoint with its own label (Setup popup) uses it.
     *
     * @return string
     */
    public static function label($checkpoint_id = '')
    {
        $own = $checkpoint_id ? self::setup($checkpoint_id)['label'] : '';

        if ('' !== $own) return $own;

        $label = fusewp_get_settings('optin_checkbox_label');

        return ! empty($label) ? $label : esc_html__('Subscribe to our newsletter', 'fusewp');
    }

    /**
     * A registration checkpoint's own settings from its Setup popup.
     *
     * @return array ['mode' => 'all'|'selected', 'forms' => string[], 'label' => string]
     */
    public static function setup($checkpoint_id)
    {
        $all   = (array)fusewp_get_settings('optin_setup');
        $saved = (array)fusewpVar($all, $checkpoint_id, []);

        return [
            'mode'  => 'selected' === fusewpVar($saved, 'mode') ? 'selected' : 'all',
            'forms' => array_values(array_filter(array_map('strval', (array)fusewpVar($saved, 'forms', [])), 'strlen')),
            'label' => trim((string)fusewpVar($saved, 'label', '')),
        ];
    }

    /**
     * The forms a checkpoint can show the box on, for its Setup popup.
     *
     * @return array form id => title; empty when the plugin has one form.
     */
    public static function checkpoint_forms($checkpoint_id)
    {
        $callback = fusewpVar(fusewpVar(self::checkpoints(), $checkpoint_id, []), 'forms');

        if ( ! is_callable($callback)) return [];

        try {
            return (array)call_user_func($callback);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Whether the box shows on this form: "All forms", or the form is one of those picked.
     */
    public static function shows_on_form($checkpoint_id, $form_id)
    {
        $setup = self::setup($checkpoint_id);

        if ('all' === $setup['mode']) return true;

        // nothing to pick from (any more), eg. down to one form: the popup offers no
        // choice, so a choice saved earlier no longer applies.
        $forms = self::checkpoint_forms($checkpoint_id);

        if (empty($forms)) return true;

        // a form the plugin doesn't name can't have been picked.
        if ('' === (string)$form_id) return false;

        return in_array((string)$form_id, $setup['forms'], true);
    }

    public static function is_prechecked()
    {
        return fusewp_get_settings('optin_checkbox_prechecked') == 'yes';
    }

    /** Contacts who signed up before the box was switched on keep syncing. */
    public static function grandfather_existing()
    {
        return fusewp_get_settings('optin_grandfather_existing') !== 'false';
    }

    public static function enabled_at()
    {
        return absint(get_option(self::ENABLED_AT_OPTION, 0));
    }

    /**
     * Saves when opt-in was first required, for grandfathering. Kept on later saves
     * and when opt-in is switched off, so contacts who joined while it was on stay gated.
     *
     * @param array $settings the settings as just saved
     */
    public function track_enabled_at($settings)
    {
        $selected = array_filter((array)fusewpVar((array)$settings, 'optin_integrations', []));

        if ( ! empty($selected) && ! self::enabled_at()) {
            update_option(self::ENABLED_AT_OPTION, time(), false);
        }
    }

    /**
     * Markup of the checkbox, empty if the checkpoint is off or the logged-in
     * user already consented.
     *
     * @param string $checkpoint_id
     * @param array $args wrapper_class, wrapper_tag
     *
     * @return string
     */
    public static function render($checkpoint_id, $args = [])
    {
        if ( ! self::is_enabled($checkpoint_id)) return '';

        if (is_user_logged_in() && OptinConsent::has('', get_current_user_id())) return '';

        $args = wp_parse_args($args, ['wrapper_class' => '', 'wrapper_tag' => 'p', 'form_id' => '']);

        if ( ! self::shows_on_form($checkpoint_id, $args['form_id'])) return '';

        $tag = tag_escape($args['wrapper_tag']);

        $html = sprintf(
            '<%1$s class="fusewp-optin-checkbox %2$s"><label><input type="checkbox" name="%3$s" value="1"%4$s> <span>%5$s</span></label>' .
            '<input type="hidden" name="%6$s" value="%7$s"></%1$s>',
            $tag,
            esc_attr($args['wrapper_class']),
            self::FIELD,
            checked(self::is_prechecked(), true, false),
            wp_kses_post(self::label($checkpoint_id)),
            self::CHECKPOINT_FIELD,
            esc_attr($checkpoint_id)
        );

        return apply_filters('fusewp_optin_checkbox_html', $html, $checkpoint_id, $args);
    }

    public static function is_ticked_in_request()
    {
        return self::$ticked || ! empty($_POST[self::FIELD]); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * For a form that posts its fields as JSON (User Registration): the box was ticked
     * in this request, for the given checkpoint.
     */
    public static function set_ticked($checkpoint_id)
    {
        self::$ticked_checkpoint = sanitize_key((string)$checkpoint_id);

        // an empty id takes it back.
        self::$ticked = '' !== self::$ticked_checkpoint;
    }

    /**
     * Records consent if the checkbox in the current request was ticked.
     */
    public static function maybe_record($email, $checkpoint_id, $user_id = 0)
    {
        if ( ! self::is_enabled($checkpoint_id) || ! self::is_ticked_in_request()) return false;

        return OptinConsent::record($email, $checkpoint_id, $user_id);
    }

    public function settings($args)
    {
        $args['optin_checkbox_settings'] = [
            'section_title'              => esc_html__('Opt-in Checkbox', 'fusewp'),
            'optin_integrations'         => [
                'type'        => 'select2',
                'label'       => esc_html__('Require Opt-in Consent For', 'fusewp'),
                'options'     => self::integration_options(),
                'description' => implode('<br>', [
                    esc_html__('For the integrations selected here, only contacts who opted in are subscribed. Integrations you don\'t select keep syncing everyone, as before. Status changes that remove a contact from a list are always synced.', 'fusewp'),
                    esc_html__('• Checkout & WordPress Registration, Registration Plugins: an opt-in checkbox is added to the form automatically.', 'fusewp'),
                    esc_html__('• Forms: add a checkbox field to your form, then map it to "Opt-in Consent" in the sync rule.', 'fusewp'),
                    esc_html__('• Others: the consent the contact gave at checkout or registration is used.', 'fusewp'),
                ]),
            ],
            'optin_setup'                => [
                'type'  => 'custom_field_block',
                'label' => esc_html__('Where the Checkbox Shows', 'fusewp'),
                'data'  => self::setup_ui(),
                'tr_id' => 'fusewp_optin_setup_row',
            ],
            'optin_checkbox_label'       => [
                'type'        => 'text',
                'label'       => esc_html__('Checkbox Label', 'fusewp'),
                'placeholder' => esc_html__('Subscribe to our newsletter', 'fusewp'),
            ],
            'optin_checkbox_prechecked'  => [
                'type'           => 'checkbox',
                'value'          => 'yes',
                'label'          => esc_html__('Pre-check the Box', 'fusewp'),
                'checkbox_label' => esc_html__('Check to Enable', 'fusewp'),
                'description'    => esc_html__('GDPR requires an unticked box, so leave this off if you sell to the EU or UK.', 'fusewp'),
            ],
            'optin_grandfather_existing' => [
                'type'           => 'checkbox',
                'value'          => 'yes',
                'default_value'  => 'yes',
                'label'          => esc_html__('Existing Contacts', 'fusewp'),
                'checkbox_label' => esc_html__('Keep syncing contacts who signed up before opt-in was required', 'fusewp'),
                'description'    => esc_html__('Applies to user accounts created, and orders placed, before you first enabled opt-in consent.', 'fusewp'),
            ],
        ];

        return $args;
    }

    /**
     * The "Where the Checkbox Shows" table: one row per registration checkpoint, shown
     * while it is selected in "Require Opt-in Consent For", with a Setup button opening
     * its popup. The popups are part of the settings form, so Save Changes saves them
     * (`optin_setup[<checkpoint>][mode|forms|label]`); optin-setup.js only shows them.
     *
     * @return string
     */
    public static function setup_ui()
    {
        $registration = array_filter(self::checkpoints(), function ($checkpoint) {
            return self::GROUP_REGISTRATION === fusewpVar($checkpoint, 'group');
        });

        if (empty($registration)) return '';

        $name = FUSEWP_SETTINGS_DB_OPTION_NAME . '[optin_setup]';

        $rows = $popups = '';

        foreach ($registration as $id => $checkpoint) {

            $setup = self::setup($id);
            $forms = self::checkpoint_forms($id);
            $field = $name . '[' . esc_attr($id) . ']';

            $rows .= sprintf(
                '<tr class="fusewp-optin-setup__row" data-checkpoint="%1$s"><td><strong>%2$s</strong><br><span class="description js-summary"></span></td>' .
                '<td class="fusewp-optin-setup__action"><button type="button" class="button js-optin-setup" data-checkpoint="%1$s"><span class="dashicons dashicons-admin-generic"></span>%3$s</button></td></tr>',
                esc_attr($id), esc_html($checkpoint['label']), esc_html__('Setup', 'fusewp')
            );

            $form_list = '';

            if ( ! empty($forms)) {

                // a site can have many forms: a select2 box rather than a checkbox each.
                $boxes = sprintf('<select class="fusewp-optin-setup__select" name="%1$s[forms][]" multiple data-placeholder="%2$s">', $field, esc_attr__('Pick the forms…', 'fusewp'));

                foreach ($forms as $form_id => $title) {
                    $boxes .= sprintf(
                        '<option value="%1$s"%2$s>%3$s</option>',
                        esc_attr($form_id), selected(in_array((string)$form_id, $setup['forms'], true), true, false), esc_html($title)
                    );
                }

                $boxes .= '</select>';

                $form_list = sprintf(
                    '<tr><th>%1$s</th><td>' .
                    '<label class="fusewp-optin-setup__mode"><input type="radio" name="%2$s[mode]" value="all"%3$s> %4$s</label>' .
                    '<label class="fusewp-optin-setup__mode"><input type="radio" name="%2$s[mode]" value="selected"%5$s> %6$s</label>' .
                    '<div class="fusewp-optin-setup__forms">%7$s</div>' .
                    '<p class="description">%8$s</p></td></tr>',
                    esc_html__('Show on', 'fusewp'), $field,
                    checked('all', $setup['mode'], false), esc_html__('All registration forms', 'fusewp'),
                    checked('selected', $setup['mode'], false), esc_html__('Only these forms', 'fusewp'),
                    $boxes,
                    esc_html__('A form left out shows no checkbox; its sign-ups sync only if they opted in somewhere else.', 'fusewp')
                );
            }

            $popups .= sprintf(
                '<div class="fusewp-optin-setup__popup" data-checkpoint="%1$s" role="dialog" aria-modal="true" aria-label="%2$s" hidden>' .
                '<div class="fusewp-optin-setup__dialog"><div class="fusewp-optin-setup__head"><h2>%2$s</h2>' .
                '<button type="button" class="fusewp-optin-setup__close js-optin-close" aria-label="%3$s"><span class="dashicons dashicons-no-alt"></span></button></div>' .
                '<table class="form-table" role="presentation"><tbody>%4$s' .
                '<tr><th>%5$s</th><td><input type="text" class="widefat" name="%6$s[label]" value="%7$s" placeholder="%8$s">' .
                '<p class="description">%9$s</p></td></tr></tbody></table>' .
                '<div class="fusewp-optin-setup__foot"><span class="description">%11$s</span><button type="button" class="button button-primary js-optin-close">%10$s</button></div></div></div>',
                esc_attr($id),
                /* translators: %s: eg. "ProfilePress Registration" */
                esc_html(sprintf(__('%s: opt-in checkbox', 'fusewp'), $checkpoint['label'])),
                esc_attr__('Close', 'fusewp'),
                $form_list,
                esc_html__('Label', 'fusewp'), $field, esc_attr($setup['label']),
                esc_attr(wp_strip_all_tags(self::label())),
                esc_html__('Leave empty to use the Checkbox Label below.', 'fusewp'),
                esc_html__('Done', 'fusewp'),
                esc_html__('Click Save Changes on the page to keep these settings.', 'fusewp')
            );
        }

        return sprintf(
            '<div class="fusewp-optin-setup" data-i18n="%1$s"><table class="widefat striped"><tbody>%2$s</tbody></table>' .
            '<p class="fusewp-optin-setup__empty description">%3$s</p>%4$s</div>',
            esc_attr(wp_json_encode([
                'all'      => __('All registration forms', 'fusewp'),
                /* translators: 1: forms picked, 2: forms in all */
                'some'     => __('%1$s of %2$s forms', 'fusewp'),
                'none'     => __('No form picked: the checkbox shows nowhere', 'fusewp'),
                'label'    => __('own label', 'fusewp'),
                'default'  => __('default label', 'fusewp'),
                'one_form' => __('Its registration form', 'fusewp'),
            ])),
            $rows,
            esc_html__('Select a registration plugin in "Require Opt-in Consent For" to choose its forms and label.', 'fusewp'),
            $popups
        );
    }

    public function setup_assets()
    {
        if ( ! isset($_GET['page']) || FUSEWP_SETTINGS_SETTINGS_SLUG !== $_GET['page']) return; // phpcs:ignore WordPress.Security.NonceVerification

        wp_enqueue_style('fusewp-optin-setup', FUSEWP_ASSETS_URL . 'css/optin-setup.css', [], FUSEWP_VERSION_NUMBER);
        wp_enqueue_script('fusewp-optin-setup', FUSEWP_ASSETS_URL . 'js/optin-setup.js', ['jquery'], FUSEWP_VERSION_NUMBER, true);
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
