<?php

namespace FuseWP\Core\Admin\SettingsPage;

// Exit if accessed directly
use FuseWP\CustomSettingsPageApi;

if ( ! defined('ABSPATH')) {
    exit;
}

class SyncLogPage
{
    protected SyncLogList $wplist_instance;

    public function __construct()
    {
        add_action('fusewp_admin_settings_page_sync-logs', [$this, 'settings_admin_page_callback']);

        add_action('fusewp_sync_register_settings_page_hook', function ($hook) {
            add_action("load-$hook", array($this, 'screen_option'));
        });
    }

    /**
     * Screen options
     */
    public function screen_option()
    {
        if (fusewpVarGET('page') != FUSEWP_SYNC_SETTINGS_SLUG || fusewpVarGET('view') != 'sync-logs') return;

        $args = [
            'label'   => esc_html__('Sync Logs', 'fusewp'),
            'default' => 20,
            'option'  => 'sync_logs_per_page',
        ];

        add_screen_option('per_page', $args);

        $this->wplist_instance = SyncLogList::get_instance();
    }

    public function settings_admin_page_callback()
    {
        add_action('wp_cspa_main_content_area', [$this, 'sync_log_page'], 10, 2);
        add_action('wp_cspa_form_tag', function ($option_name) {
            if ($option_name == 'fusewp_sync_log_page') {
                printf(' action="%s"', fusewp_get_current_url_query_string());
            }
        });

        $settingsPageInstance = CustomSettingsPageApi::instance([], 'fusewp_sync_log_page', esc_html__('Sync Logs', 'fusewp'));
        $settingsPageInstance->form_method('get');
        $settingsPageInstance->sidebar(AbstractSettingsPage::sidebar_args());
        $settingsPageInstance->build();
    }

    public function sync_log_page()
    {
        $this->wplist_instance->prepare_items();

        ob_start();
        echo '<input type="hidden" name="page" value="' . FUSEWP_SYNC_SETTINGS_SLUG . '" />';
        echo '<input type="hidden" name="view" value="sync-logs" />';
        $this->wplist_instance->search_box(esc_html__('Search Logs', 'fusewp'), 'fusewp-log');
        $this->wplist_instance->display();

        return ob_get_clean();
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