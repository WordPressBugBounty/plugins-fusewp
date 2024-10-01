<?php

namespace FuseWP\Core\Admin\SettingsPage;

// Exit if accessed directly
use FuseWP\CustomSettingsPageApi;

if ( ! defined('ABSPATH')) {
    exit;
}

class SyncPage extends AbstractSettingsPage
{
    /** @var CustomSettingsPageApi */
    public $settingsPageInstance;
    /**
     * @var SyncList
     */
    protected $wplist_instance;

    public function __construct()
    {
        add_action('fusewp_register_menu_page', [$this, 'register_settings_page']);
        add_action('fusewp_admin_settings_page_sync-setup', [$this, 'settings_admin_page_callback']);

        add_filter('set-screen-option', [$this, 'set_screen'], 10, 3);
        add_filter('set_screen_option_sync_rules_per_page', [$this, 'set_screen'], 10, 3);

        add_action('admin_init', [$this, 'save_changes']);

        add_filter('fusewp_admin_js_localize_args', [$this, 'localize_js']);
    }

    public function localize_js($l10n_args)
    {
        if (fusewpVarGET('fusewp_sync_action') == 'edit' && ! empty($_GET['id'])) {
            $rule = fusewp_sync_get_rule(intval($_GET['id']));

            if (isset($rule->source)) {

                $source = fusewp_get_registered_sync_sources(fusewp_sync_get_real_source_id($rule->source));

                if (is_object($source) && method_exists($source, 'get_destination_items')) {
                    $l10n_args['fusewp_destination_items']      = $source->get_destination_items();
                    $l10n_args['fusewp_destination_item_label'] = $source->get_destination_item_label();
                }
            }
        }

        return $l10n_args;
    }

    /**
     * Save screen option.
     *
     * @param string $status
     * @param string $option
     * @param string $value
     *
     * @return mixed
     */
    public function set_screen($status, $option, $value)
    {
        return $value;
    }

    /**
     * Screen options
     */
    public function screen_option()
    {
        if (fusewpVarGET('page') != FUSEWP_SYNC_SETTINGS_SLUG) return;

        $args = [
            'label'   => esc_html__('Sync Rules', 'fusewp'),
            'default' => 10,
            'option'  => 'sync_rules_per_page',
        ];

        add_screen_option('per_page', $args);

        if (isset($_GET['id']) || fusewpVarGET('fusewp_sync_action') == 'add') {
            add_filter('screen_options_show_screen', '__return_false');
        }

        $this->wplist_instance = SyncList::get_instance();
    }

    public function register_settings_page()
    {
        $hook = add_submenu_page(
            FUSEWP_SETTINGS_SETTINGS_SLUG,
            $this->admin_page_title(),
            __('User Sync', 'fusewp'),
            'manage_options',
            FUSEWP_SYNC_SETTINGS_SLUG,
            array($this, 'admin_page_callback')
        );

        add_action("load-$hook", array($this, 'screen_option'));

        do_action('fusewp_sync_register_settings_page_hook', $hook);
    }

    public function default_header_menu()
    {
        return 'sync-setup';
    }

    public function header_menu_tabs()
    {
        $tabs = apply_filters('fusewp_sync_header_menu_tabs', [
            10 => ['id' => 'sync-setup', 'url' => FUSEWP_SYNC_SETTINGS_PAGE, 'label' => esc_html__('Sync Rules', 'fusewp')],
            20 => ['id' => 'sync-logs', 'url' => FUSEWP_SYNC_LOGS_SETTINGS_PAGE, 'label' => esc_html__('Logs', 'fusewp')],
        ]);

        ksort($tabs);

        return $tabs;
    }

    public function admin_page_title()
    {
        $page_title = esc_html__('User Sync', 'fusewp');

        if (isset($_GET['page'], $_GET['fusewp_sync_action']) && $_GET['page'] == FUSEWP_SYNC_SETTINGS_SLUG && $_GET['fusewp_sync_action'] == 'add') {
            $page_title = esc_html__('Add Sync Rule', 'fusewp');
        }

        if (fusewpVarGET('fusewp_sync_action') == 'edit' && isset($_GET['id'])) {
            $page_title = esc_html__('Edit Sync Rule', 'fusewp');
        }

        if (isset($_GET['page'], $_GET['view']) && $_GET['view'] == 'sync-logs') {
            $page_title = esc_html__('Sync Logs', 'fusewp');
        }

        return $page_title;
    }

    protected function is_sync_add_edit_page()
    {
        return fusewpVarGET('fusewp_sync_action') == 'add' ||
               fusewpVarGET('fusewp_sync_action') == 'edit';
    }

    public function settings_admin_page_callback()
    {
        if ($this->is_sync_add_edit_page()) {
            add_action('admin_footer', [$this, 'js_template']);
            require_once dirname(__FILE__) . '/views/sync/add-edit-sync-rule.php';

            return;
        }

        add_action('wp_cspa_main_content_area', [$this, 'sync_setup_page'], 10, 2);
        add_action('wp_cspa_before_closing_header', [$this, 'add_new_button']);

        $settingsPageInstance = CustomSettingsPageApi::instance([], 'fusewp_sync_page', esc_html__('Sync Rules', 'fusewp'));
        $settingsPageInstance->sidebar(self::sidebar_args());
        $settingsPageInstance->build($this->is_sync_add_edit_page());
    }

    public function add_new_button()
    {
        $url = esc_url(SyncList::add_url());
        echo "<a class=\"add-new-h2\" href=\"$url\">" . esc_html__('Add New', 'fusewp') . '</a>';
    }

    public function sync_setup_page($content, $option_name)
    {
        if ('fusewp_sync_page' != $option_name) return $content;

        $this->wplist_instance->prepare_items();

        ob_start();
        $this->wplist_instance->display();

        return ob_get_clean();
    }

    public function save_changes()
    {
        if (fusewpVarGET('fusewp_sync_action') != 'add' && fusewpVarGET('fusewp_sync_action') != 'edit') return;

        if ( ! isset($_POST['fusewp_save_sync_rule'])) return;

        // store source with item if item exists and is selected.
        if (isset($_POST['fusewp_sync_source_item'])) {
            $_POST['fusewp_sync_source'] = $_POST['fusewp_sync_source_item'];
        }

        if ( ! empty($_GET['id'])) {

            $rule_id = fusewp_update_sync_rule_settings(absint($_GET['id']), $_POST);

        } else {

            $rule_id = fusewp_add_sync_rule_settings($_POST);

            if (is_wp_error($rule_id)) {
                $this->trigger_admin_notices($rule_id->get_error_message());

                return;
            }
        }

        wp_safe_redirect(SyncList::edit_url($rule_id));
        exit;
    }

    public function js_template()
    {
        ?>
        <script type="text/html" id="tmpl-fusewp-source-information">
            <div class="fusewp-sync-source-info">{{{data.message}}}</div>
        </script>

        <script type="text/html" id="tmpl-fusewp-destination-item">
            <div data-index="{{data.index}}" class="fusewp-action fusewp-open">
                <div class="fusewp-action__header">
                    <div class="row-options">
                        <a class="fusewp-edit-action" href="#"><?php esc_html_e('Edit', 'fusewp'); ?></a>
                        <a class="fusewp-delete-action" href="#"><?php esc_html_e('Delete', 'fusewp'); ?></a>
                    </div>
                    <h4 class="action-title"><?php esc_html_e('New Destination', 'fusewp'); ?></h4>
                </div>

                <div class="fusewp-action__fields">
                    <table class="fusewp-table">
                        <tbody>
                        <tr class="fusewp-table__row">
                            <td class="fusewp-table__col fusewp-table__col--label">
                                <label>{{data.source_item_name}} <span class="required">*</span></label>
                            </td>
                            <td class="fusewp-table__col fusewp-table__col--field">
                                <select name="fusewp_sync_destinations[{{data.index}}][destination_item]" class="fusewp-field fusewp-field--type-select fusewp-action-select" required>
                                    <option value="">&mdash;&mdash;&mdash;</option>
                                    <# jQuery.each(data.destination_items, function(index, value) { #>
                                    <# var disabled_flag = index.indexOf('fusewp_disabled') !== -1 ? ' disabled' : ''; #>
                                    <option{{disabled_flag}} value="{{index}}">{{value}}</option>
                                    <# }); #>
                                </select>
                            </td>
                        </tr>
                        <tr class="fusewp-table__row">
                            <td class="fusewp-table__col fusewp-table__col--label">
                                <label><?php esc_html_e('Select Integration', 'fusewp'); ?>
                                    <span class="required">*</span>
                                </label>
                            </td>
                            <td class="fusewp-table__col fusewp-table__col--field">
                                <select name="fusewp_sync_destinations[{{data.index}}][integration]" class="fusewp-field fusewp-field--type-select fusewp-integration-select" required>
                                    <option value="">&mdash;&mdash;&mdash;</option>
                                    <?php foreach (fusewp_get_registered_sync_integrations('', true) as $integration) : ?>
                                        <option value="<?php echo esc_attr($integration->id) ?>"><?php echo esc_html($integration->title) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </script>
        <?php
    }

    /**
     * @return self
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}