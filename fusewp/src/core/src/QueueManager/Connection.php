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

    /**
     * If an unsubscribe sync job of a user has another job to resubscribe them again in queue,
     * remove the unsubscribe job from queue.
     *
     * @return void
     */
    protected function remove_unsubscribe_sync_jobs_with_resubscribe_action()
    {
        if (defined('FUSEWP_BULK_SYNC_PROCESS_TASK')) return;

        // limit to 10 so we don't have to deal with large queue data - just in case.
        $sql = "SELECT id, job FROM wp_fusewp_queue_jobs WHERE job LIKE '%subscribe_user%' LIMIT 10";

        $result = $this->database->get_results($sql);

        $subscribe_bucket   = [];
        $unsubscribe_bucket = [];

        $unsubscribe_jobs_to_remove = [];

        foreach ($result as $item) {
            $job = unserialize($item->job);
            if (isset($job->job_args["action"]) && "subscribe_user" == $job->job_args["action"]) {
                $subscribe_bucket[$item->id] = $job->job_args;
            }

            if (isset($job->job_args["action"]) && "unsubscribe_user" == $job->job_args["action"]) {
                $unsubscribe_bucket[$item->id] = $job->job_args;
            }
        }

        foreach ($unsubscribe_bucket as $db_id => $item) {
            $source_id     = $item['source_id'] ?? '';
            $list_id       = $item['list_id'] ?? '';
            $email_address = $item['email_address'] ?? '';
            $integration   = $item['integration'] ?? '';

            foreach ($subscribe_bucket as $_db_id => $_item) {

                $_source_id     = $_item['source_id'] ?? '';
                $_list_id       = $_item['list_id'] ?? '';
                $_email_address = $_item['email_address'] ?? '';
                $_integration   = $_item['integration'] ?? '';

                if (
                    $_source_id == $source_id &&
                    $_list_id == $list_id &&
                    $_email_address == $email_address &&
                    $_integration == $integration
                ) {

                    $unsubscribe_jobs_to_remove[] = $db_id;
                }
            }
        }

        if ( ! empty($unsubscribe_jobs_to_remove)) {
            $this->database->query(
                $this->database->prepare(
                    "DELETE FROM {$this->jobs_table} WHERE id IN (" . implode(',', array_fill(0, count($unsubscribe_jobs_to_remove), '%d')) . ")",
                    $unsubscribe_jobs_to_remove
                )
            );
        }
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
        $this->remove_unsubscribe_sync_jobs_with_resubscribe_action();
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