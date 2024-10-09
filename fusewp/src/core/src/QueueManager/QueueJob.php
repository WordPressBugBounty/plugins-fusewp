<?php

namespace FuseWP\Core\QueueManager;

use \FuseWPVendor\WP_Queue\Job;

class QueueJob extends Job
{
    public $job_args = [];

    /**
     * @param array $job_args
     */
    public function __construct($job_args)
    {
        $this->job_args = $job_args;
    }

    public function handle()
    {
        do_action('fusewp_queued_job_handler', $this->job_args);
    }
}