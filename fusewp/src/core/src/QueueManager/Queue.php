<?php

namespace FuseWP\Core\QueueManager;

use FuseWPVendor\WP_Queue\Connections\SyncConnection;
use FuseWPVendor\WP_Queue\Job;

class Queue extends \FuseWPVendor\WP_Queue\Queue
{
    public function __construct()
    {
        global $wpdb;

        $connection = new Connection($wpdb);

        if (defined('FUSEWP_BULK_SYNC_PROCESS_TASK') || $this->should_process_sync_immediately()) {
            $connection = new SyncConnection();
        }

        parent::__construct($connection);
    }

    public function push(Job $job, $delay = 0, $priority = 0)
    {
        return $this->connection->push($job, $delay, $priority);
    }

    public function should_process_sync_immediately()
    {
        return apply_filters('fusewp_should_process_sync_immediately', false, $this);
    }
}