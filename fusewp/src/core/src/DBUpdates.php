<?php

namespace FuseWP\Core;

class DBUpdates
{
    public static $instance;

    const DB_VER = 1;

    public function init_options()
    {
        add_option('fusewp_db_ver', 0);
    }

    public function maybe_update()
    {
        $this->init_options();

        if (get_option('fusewp_db_ver', 0) >= self::DB_VER) {
            return;
        }

        // update plugin
        $this->update();
    }

    public function update()
    {
        // no PHP timeout for running updates
        fusewp_set_time_limit();

        // this is the current database schema version number
        $current_db_ver = get_option('fusewp_db_ver');

        // this is the target version that we need to reach
        $target_db_ver = self::DB_VER;

        // run update routines one by one until the current version number
        // reaches the target version number
        while ($current_db_ver < $target_db_ver) {
            // increment the current db_ver by one
            $current_db_ver++;

            // each db version will require a separate update function
            $update_method = "update_routine_{$current_db_ver}";

            if (method_exists($this, $update_method)) {
                call_user_func(array($this, $update_method));
            }
        }

        // update the option in the database, so that this process can always
        // pick up where it left off
        update_option('fusewp_db_ver', $current_db_ver);
    }

    public function update_routine_1()
    {
        global $wpdb;
        require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = Core::queue_db_table();
        $wpdb->hide_errors();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
                    id bigint(20) NOT NULL AUTO_INCREMENT,
                    priority bigint(20) NOT NULL DEFAULT 0,
                    job longtext NOT NULL,
                    attempts tinyint(3) NOT NULL DEFAULT 0,
                    reserved_at datetime DEFAULT NULL,
                    available_at datetime NOT NULL,
                    created_at datetime NOT NULL,
                    PRIMARY KEY (id)
                ) {$charset_collate};";

        \dbDelta($sql);
    }

    public static function get_instance()
    {
        if ( ! isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}