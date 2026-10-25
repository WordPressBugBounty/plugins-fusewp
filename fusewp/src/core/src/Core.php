<?php

namespace FuseWP\Core;

final class Core
{
    const sync_table_name = 'fusewp_sync';
    const sync_log_table_name = 'fusewp_sync_log';
    const queue_table_name = 'fusewp_queue_jobs';
    const optin_consent_table_name = 'fusewp_optin_consents';

    public function __construct()
    {
        Base::get_instance();
    }

    public static function sync_rule_db_table()
    {
        global $wpdb;

        return $wpdb->prefix . self::sync_table_name;
    }

    public static function sync_log_db_table()
    {
        global $wpdb;

        return $wpdb->prefix . self::sync_log_table_name;
    }

    public static function queue_db_table()
    {
        global $wpdb;

        return $wpdb->prefix . self::queue_table_name;
    }

    public static function optin_consent_db_table()
    {
        global $wpdb;

        return $wpdb->prefix . self::optin_consent_table_name;
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }

    public static function init()
    {
        Core::get_instance();

        do_action('fusewp_loaded');
    }
}