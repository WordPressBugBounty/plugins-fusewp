<?php

namespace FuseWPVendor;

use FuseWPVendor\WP_Queue\Queue;
use FuseWPVendor\WP_Queue\QueueManager;
if (!\function_exists('FuseWPVendor\wp_queue')) {
    /**
     * Return Queue instance.
     *
     * @param string $connection
     * @param array  $allowed_job_classes Job classes that may be handled. Default, any Job subclass.
     *
     * @return Queue
     * @throws Exception
     */
    function wp_queue($connection = '', array $allowed_job_classes = [])
    {
        if (empty($connection)) {
            $connection = \apply_filters('wp_queue_default_connection', 'database');
        }
        return QueueManager::resolve($connection, $allowed_job_classes);
    }
}
if (!\function_exists('FuseWPVendor\wp_queue_install_tables')) {
    /**
     * Install database tables
     */
    function wp_queue_install_tables()
    {
        global $wpdb;
        require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
        $wpdb->hide_errors();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$wpdb->prefix}queue_jobs (\n\t\t\t\tid bigint(20) NOT NULL AUTO_INCREMENT,\n\t\t\t\tjob longtext NOT NULL,\n\t\t\t\tattempts tinyint(3) NOT NULL DEFAULT 0,\n\t\t\t\treserved_at datetime DEFAULT NULL,\n\t\t\t\tavailable_at datetime NOT NULL,\n\t\t\t\tcreated_at datetime NOT NULL,\n\t\t\t\tPRIMARY KEY  (id)\n\t\t\t\t) {$charset_collate};";
        \dbDelta($sql);
        $sql = "CREATE TABLE {$wpdb->prefix}queue_failures (\n\t\t\t\tid bigint(20) NOT NULL AUTO_INCREMENT,\n\t\t\t\tjob longtext NOT NULL,\n\t\t\t\terror text DEFAULT NULL,\n\t\t\t\tfailed_at datetime NOT NULL,\n\t\t\t\tPRIMARY KEY  (id)\n\t\t\t\t) {$charset_collate};";
        \dbDelta($sql);
    }
}
