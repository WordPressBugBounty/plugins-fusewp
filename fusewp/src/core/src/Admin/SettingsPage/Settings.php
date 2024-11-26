<?php

namespace FuseWP\Core\Admin\SettingsPage;

// Exit if accessed directly
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\RegisterActivation\CreateDBTables;
use FuseWP\CustomSettingsPageApi;

if ( ! defined('ABSPATH')) {
    exit;
}

class Settings extends AbstractSettingsPage
{
    /** @var CustomSettingsPageApi */
    public $settingsPageInstance;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_core_menu'));

        add_action('fusewp_register_menu_page', array($this, 'register_settings_page'));

        add_action('fusewp_register_menu_page_general_', function () {
            $this->settingsPageInstance = CustomSettingsPageApi::instance([], FUSEWP_SETTINGS_DB_OPTION_NAME, esc_html__('General', 'fusewp'));
        });

        add_action('admin_init', [$this, 'install_missing_db_tables']);
        add_action('admin_init', [$this, 'clear_bulk_sync_queue']);

        add_action('fusewp_admin_settings_page_general', [$this, 'settings_admin_page_callback']);

        add_action('wp_cspa_after_post_body_content', [$this, 'integration_listing']);

        add_action('admin_init', [$this, 'save_oauth_credentials']);

        add_action('admin_init', [$this, 'oauth_disconnect_handler']);
        add_action('admin_init', [$this, 'clear_cache_handler']);

        add_action('admin_menu', [$this, 'upsell_menu_item'], 9999);
        add_action('admin_head', [$this, 'style_upsell_menu_item']);
    }

    public function upsell_menu_item()
    {
        if ( ! defined('FUSEWP_DETACH_LIBSODIUM')) {

            add_submenu_page(
                FUSEWP_SETTINGS_SETTINGS_SLUG,
                esc_html__('Upgrade for 20% Off', 'fusewp'),
                esc_html__('Upgrade for 20% Off', 'fusewp'),
                'manage_options',
                'https://bit.ly/4aMqWLX'
            );
        }
    }

    public function style_upsell_menu_item()
    {
        if ( ! defined('FUSEWP_DETACH_LIBSODIUM')) {
            echo '<style>#toplevel_page_fusewp-settings ul.wp-submenu li:last-child a[href^="https://bit.ly"] { background-color: #8d00b1d9 !important; color: #fff !important; font-weight: 600 !important; } #toplevel_page_fusewp-settings.wp-not-current-submenu ul.wp-submenu  li:last-child a[href^="https://bit.ly"] {padding-left: 6px;}</style>';
            echo '<script>jQuery(function() {jQuery(\'#toplevel_page_fusewp-settings ul.wp-submenu li:last-child a[href^="https://bit.ly"]\').attr("target", "_blank");});</script>';
        }
    }

    public function register_settings_page()
    {
        add_submenu_page(
            FUSEWP_SETTINGS_SETTINGS_SLUG,
            __('Settings - FuseWP', 'fusewp'),
            __('Settings', 'fusewp'),
            'manage_options',
            FUSEWP_SETTINGS_SETTINGS_SLUG,
            array($this, 'admin_page_callback')
        );
    }

    public function default_header_menu()
    {
        return apply_filters('fusewp_settings_default_header_menu', 'general');
    }

    public function header_menu_tabs()
    {
        $tabs = apply_filters('fusewp_settings_header_menu_tabs', [
            10 => [
                'id'    => 'general',
                'url'   => FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE,
                'label' => esc_html__('General', 'fusewp')
            ]
        ]);

        ksort($tabs);

        return $tabs;
    }

    public function save_oauth_credentials()
    {
        if ( ! isset($_GET['fusewp-save-auth'])) return;

        $integration_id = sanitize_text_field($_GET['fusewp-save-auth']);

        check_admin_referer(sprintf('fusewp_%s_auth', $integration_id), 'fwpnonce');

        $data = fusewp_clean($_GET);

        unset($data['page'], $data['view'], $data['fusewp-save-auth'], $data['fwpnonce'], $data['token_type']);

        $this->custom_save_settings(
            apply_filters('fusewp_before_save_oauth_credentials', [$integration_id => $data], $integration_id)
        );

        do_action('fusewp_after_save_oauth_credentials', $data);

        wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
        exit;
    }

    public function oauth_disconnect_handler()
    {
        if ( ! empty($_GET['fusewp-integration-disconnect'])) {
            $integration_id = sanitize_text_field($_GET['fusewp-integration-disconnect']);
            check_admin_referer('fusewp_' . $integration_id . '_disconnect', 'fwpnonce');

            $this->custom_save_settings([$integration_id => []]);

            do_action('fusewp_oauth_disconnection_' . $integration_id);

            wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
            exit;
        }
    }

    public function clear_cache_handler()
    {
        if ( ! empty($_GET['fusewp-integration-clear-cache'])) {
            $integration_id = sanitize_text_field($_GET['fusewp-integration-clear-cache']);
            check_admin_referer('fusewp_' . $integration_id . '_clear_cache', 'fwpnonce');

            delete_transient('fusewp_' . $integration_id . '_email_list');

            do_action('fusewp_cache_clearing_' . $integration_id);

            wp_safe_redirect(FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
            exit;
        }
    }

    public function settings_admin_page_callback()
    {
        $fix_db_url = wp_nonce_url(
            add_query_arg('fusewp-install-missing-db', 'true', FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE),
            'fusewp_install_missing_db_tables'
        );

        $clear_bulk_sync_queue_url = wp_nonce_url(
            add_query_arg('fusewp-clear-bulk-sync-queue', 'true', FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE),
            'fusewp_clear_bulk_sync_queue'
        );

        $args = [
            'global_settings' => apply_filters('fusewp_global_settings_page', [
                'section_title'                       => esc_html__('Global Settings', 'fusewp'),
                'enable_external_profile_update_sync' => [
                    'type'        => 'checkbox',
                    'value'       => 'yes',
                    'label'       => esc_html__('Enable Other Sync on Profile Update', 'fusewp'),
                    'description' => esc_html__('Check this box if you want to trigger synchronization for our various membership and ecommerce plugin integrations when users profile are updated.', 'fusewp'),
                ],
                'install_missing_db_tables'           => [
                    'type'  => 'custom_field_block',
                    'label' => __('Install Missing DB Tables', 'fusewp'),
                    'data'  => "<a href='$fix_db_url' class='button action fusewp-confirm-delete'>" . __('Fix Database', 'fusewp') . '</a>',
                ],
                'clear_bulk_sync_queue'           => [
                    'type'  => 'custom_field_block',
                    'label' => __('Clear Bulk-Sync Queue', 'fusewp'),
                    'data'  => "<a href='$clear_bulk_sync_queue_url' class='button action fusewp-confirm-delete'>" . __('Clear Queued Jobs', 'fusewp') . '</a>',
                ],
                'remove_plugin_data'                  => [
                    'type'           => 'checkbox',
                    'value'          => 'yes',
                    'label'          => esc_html__('Remove Data on Uninstall', 'fusewp'),
                    'checkbox_label' => esc_html__('Delete', 'fusewp'),
                    'description'    => esc_html__('Check this box if you would like FuseWP to completely remove all of its data when the plugin is deleted.', 'fusewp'),
                ]
            ])
        ];

        $this->settingsPageInstance->main_content(apply_filters('fusewp_settings_page', $args));
        $this->settingsPageInstance->remove_white_design();
        $this->settingsPageInstance->remove_h2_header();
        $this->settingsPageInstance->sidebar(self::sidebar_args());
        $this->settingsPageInstance->build();
    }

    public function integration_listing($option_name)
    {
        if ('fusewp_settings' != $option_name) return;

        printf('<div class="postbox-header"><h3 class="hndle"><span>%s</span></h3></div>', esc_html__('Integrations', 'fusewp'));

        $integrations = fusewp_get_registered_integrations();

        echo '<div class="fusewp-integrations-wrap">';
        /** @var AbstractIntegration $integration */
        foreach ($integrations as $integration) {

            $is_connected = $integration->is_connected();

            printf('<div class="fusewp-integration-tile%s">', $is_connected ? ' fs-active' : '');
            printf('<div class="fusewp-integration-icon" style="background-image: url(%s)">%s</div>', $integration->logo_url, $integration->title);
            printf('<div class="fusewp-integration-name">%s</div>', $integration->title);
            printf('<a href="#%s-modal-settings" rel="modal:open" class="button">%s</a>', $integration->id, esc_html__('Configure', 'fusewp'));
            echo '</div>';
            printf('<div id="%s-modal-settings" class="modal fusewp-modal-settings-wrap">%s</div>', $integration->id, $integration->connection_settings(), esc_html__('Close', 'fusewp'));
        }
        echo '</div>';
    }

    public function install_missing_db_tables()
    {
        if (defined('DOING_AJAX')) return;

        if (fusewpVarGET('fusewp-install-missing-db') == 'true' && current_user_can('manage_options')) {

            check_admin_referer('fusewp_install_missing_db_tables');

            delete_option('fwp_db_ver');

            CreateDBTables::make();

            wp_safe_redirect(add_query_arg('settings-updated', 'true', FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE));
            exit;
        }
    }

    public function clear_bulk_sync_queue()
    {
        if (defined('DOING_AJAX')) return;

        if (fusewpVarGET('fusewp-clear-bulk-sync-queue') == 'true' && current_user_can('manage_options')) {

            check_admin_referer('fusewp_clear_bulk_sync_queue');

            global $wpdb;

            $jobs_table = $wpdb->prefix . 'fusewp_queue_jobs';

            $wpdb->query("DELETE FROM $jobs_table WHERE job LIKE '%fwp_bulk_syncing%'");

            wp_safe_redirect(add_query_arg('settings-updated', 'true', FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE));
            exit;
        }
    }

    /**
     * @return Settings
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