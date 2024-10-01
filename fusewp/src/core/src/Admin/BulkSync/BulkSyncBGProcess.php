<?php

namespace FuseWP\Core\Admin\BulkSync;

use FuseWP\Core\Libs\WP_Background_Process;

class BulkSyncBGProcess extends WP_Background_Process
{
    protected $action = 'fusewp_bg_process';

    // process task bg cron task every minute
    protected $cron_interval = 1;

    public function __construct()
    {
        // Uses unique prefix per blog so each blog has separate queue.
        $this->prefix = 'wp_' . get_current_blog_id();

        parent::__construct();

        add_filter($this->identifier . '_seconds_between_batches', function ($seconds) {
            return apply_filters('fusewp_bulk_rate_throttle_seconds', $seconds);
        });
    }

    public function save()
    {
        $key = $this->generate_key();
        if ( ! empty($this->data)) {
            $result = \update_site_option($key, $this->data);

            if ( ! $result) {
                fusewp_set_bulk_sync_flag('fwp_bsp_save_failed');
            }
        }
        // Clean out data so that new data isn't prepended with closed session's data.
        $this->data = array();

        return $this;
    }

    /**
     * @inherit_doc
     */
    public function dispatch()
    {
        $result = parent::dispatch();

        if (is_wp_error($result)) {
            fusewp_log_error('', $result->get_error_message());
            fusewp_set_bulk_sync_flag('fwp_bsp_dispatch_failed');
        }

        return $result;
    }

    protected function task($item)
    {
        if ( ! defined('FUSEWP_BULK_SYNC_PROCESS_TASK')) {
            define('FUSEWP_BULK_SYNC_PROCESS_TASK', 'true');
        }

        fusewp_get_registered_sync_sources($item['s'])->bulk_sync_handler($item);

        return false;
    }

    protected function completed()
    {
        fusewp_set_bulk_sync_flag('fwp_bsp_completed');
        parent::completed();
    }
}