<?php

namespace FuseWP\Core\RegisterActivation;


use FuseWP\Core\Core;

class CreateDBTables
{
    public static function make()
    {
        global $wpdb;

        $collate = '';
        if ($wpdb->has_cap('collation')) {
            $collate = $wpdb->get_charset_collate();
        }

        $sync_table     = Core::sync_rule_db_table();
        $sync_log_table = Core::sync_log_db_table();

        $sqls[] = "CREATE TABLE IF NOT EXISTS $sync_table (
                  id bigint(20) NOT NULL AUTO_INCREMENT,
                  source varchar(50) NOT NULL,
                  destinations longtext NULL,
                  status char(10) NOT NULL,
                  PRIMARY KEY (id),
                  UNIQUE KEY source (source)
                ) $collate;
				";

        $sqls[] = "CREATE TABLE IF NOT EXISTS $sync_log_table (
                   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                   error_message longtext,
                   integration varchar(200) DEFAULT NULL,
                   date datetime NOT NULL,
                  PRIMARY KEY (id)
                ) $collate;
				";

        $sqls = apply_filters('fusewp_create_database_tables', $sqls, $collate);

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        foreach ($sqls as $sql) {
            dbDelta($sql);
        }
    }
}