<?php

namespace FuseWP\Core\QueueManager;

use FuseWPVendor\WP_Queue\Connections\DatabaseConnection;
use FuseWPVendor\WP_Queue\Job;

class Connection extends DatabaseConnection
{
    public function __construct($wpdb)
    {
        parent::__construct($wpdb, []);

        $this->jobs_table = $wpdb->prefix . 'fusewp_queue_jobs';
    }

    public function push(Job $job, $delay = 0, $priority = 0)
    {
        $result = $this->database->insert(
            $this->jobs_table,
            [
                'job'          => serialize($job),
                'priority'     => $priority,
                'available_at' => $this->datetime($delay),
                'created_at'   => $this->datetime()
            ]
        );

        if ( ! $result) {
            return false;
        }

        return $this->database->insert_id;
    }

    public function pop()
    {
        $this->release_reserved();
        $sql     = $this->database->prepare("\n\t\t\tSELECT * FROM {$this->jobs_table}\n\t\t\tWHERE reserved_at IS NULL\n\t\t\tAND available_at <= %s\n\t\t\tORDER BY priority, available_at, id\n\t\t\tLIMIT 1\n\t\t", $this->datetime());
        $raw_job = $this->database->get_row($sql);
        if (is_null($raw_job)) {
            return false;
        }
        $job = $this->vitalize_job($raw_job);
        if ($job && is_a($job, Job::class)) {
            $this->reserve($job);
        }

        return $job;
    }

    /**
     * Push a job onto the failure queue.
     *
     * @param Job $job
     * @param \Exception $exception
     *
     * @return bool
     */
    public function failure($job, \Exception $exception)
    {
        $this->delete($job);

        return true;
    }

    public function failed_jobs()
    {
        return [];
    }
}