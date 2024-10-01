<?php

namespace FuseWP\Core\Admin\SettingsPage;

// Exit if accessed directly
use FuseWP\CustomSettingsPageApi;

if ( ! defined('ABSPATH')) {
    exit;
}

class SyncLogPage
{
    /** @var CustomSettingsPageApi */
    public $settingsPageInstance;
    /**
     * @var SyncList
     */
    protected $wplist_instance;

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

        $settingsPageInstance = CustomSettingsPageApi::instance([], 'fusewp_sync_log_page', esc_html__('Sync Logs', 'fusewp'));
        $settingsPageInstance->sidebar(AbstractSettingsPage::sidebar_args());
        $settingsPageInstance->build();
    }

    public function sync_log_page()
    {
        $this->wplist_instance->prepare_items();

        ob_start();
        /** @todo add search to search log as well as maybe a dropdown to filter by sync integration and by from and to date */
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