<?php

namespace FuseWP\Core\RegisterActivation;

class Base
{
    public static function run_install($networkwide = false)
    {
        if (is_multisite() && $networkwide) {

            $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);

            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                self::fusewp_install();
                restore_current_blog();
            }
        } else {
            self::fusewp_install();
        }
    }

    /**
     * Run plugin install / activation action when new blog is created in multisite setup.
     *
     * @param int $blog_id
     */
    public static function multisite_new_blog_install($blog_id)
    {
        if ( ! function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active_for_network('fusewp/fusewp.php')) {
            switch_to_blog($blog_id);
            self::fusewp_install();
            restore_current_blog();
        }
    }

    /**
     * Perform plugin activation / installation.
     */
    public static function fusewp_install()
    {
        if ( ! current_user_can('activate_plugins') || get_option('fusewp_plugin_activated') == 'true') {
            return;
        }

        CreateDBTables::make();

        add_option('fusewp_install_date', current_time('mysql'));
        add_option('fusewp_plugin_activated', 'true');
    }
}