<?php

//if uninstall not called from WordPress exit
use FuseWP\Core\Core;

if ( ! defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

include_once(dirname(__FILE__) . '/fusewp.php');

function fusewp_mo_uninstall_function()
{
    if (fusewp_get_settings('remove_plugin_data') == 'yes') {

        global $wpdb;

        $drop_tables[] = "DROP TABLE IF EXISTS " . Core::sync_rule_db_table();
        $drop_tables[] = "DROP TABLE IF EXISTS " . Core::sync_log_db_table();
        $drop_tables[] = "DROP TABLE IF EXISTS " . Core::queue_db_table();

        foreach ($drop_tables as $tables) {
            $wpdb->query($tables);
        }

        delete_option(FUSEWP_SETTINGS_DB_OPTION_NAME);
        delete_option('fusewp_install_date');

        delete_option('fusewp_bulk_sync_flag');
        delete_option('fusewp_dismiss_leave_review_forever');
        delete_option('fusewp_upgrader_success_flag');
        delete_option('fusewp_connect_token');
        delete_option('fusewp_bulk_sync_processed_cache');

        delete_option('fusewp_license_status');
        delete_option('fusewp_license_expired_status');
        delete_option('fusewp_license_key');
        delete_option('fusewp_plugin_activated');
        delete_option('fusewp_db_ver');

        delete_site_option('pand-' . md5('fusewp-review-plugin-notice'));

        wp_cache_flush();
    }
}

if ( ! is_multisite()) {
    fusewp_mo_uninstall_function();
} else {

    if ( ! wp_is_large_network()) {

        $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);

        foreach ($site_ids as $site_id) {
            switch_to_blog($site_id);
            fusewp_mo_uninstall_function();
            restore_current_blog();
        }
    }
}