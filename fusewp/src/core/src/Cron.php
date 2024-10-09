<?php

namespace FuseWP\Core;


class Cron
{
    public function __construct()
    {
        add_action('init', [$this, 'create_recurring_schedule']);

        add_action('fusewp_daily_recurring_job', [$this, 'clear_stale_bulk_sync_processed_records']);
    }

    public function create_recurring_schedule()
    {
        if ( ! wp_next_scheduled('fusewp_daily_recurring_job')) {
            wp_schedule_event(time(), 'daily', 'fusewp_daily_recurring_job');
        }
    }

    /**
     * Delete fusewp_bulk_sync_processed_cache option if last processed date is over 4 days ago
     */
    public function clear_stale_bulk_sync_processed_records()
    {
        $output = get_option('fusewp_bulk_sync_processed_cache', []);

        if ( ! empty($output['last_processed'])) {

            $last_processed = absint($output['last_processed']) + (4 * DAY_IN_SECONDS);

            if (time() >= $last_processed) {
                delete_option('fusewp_bulk_sync_processed_cache');
            }
        }
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