<?php

namespace FuseWP\Core\QueueManager;

if ( ! defined('ABSPATH')) {
    exit;
}

class QueueManager
{
    private $queue;

    public function __construct()
    {
        $this->queue = new Queue();
    }

    /**
     * Lowest priority are given precedence. I.e 0 get processed before 1
     *
     * @param mixed $args
     * @param int $delay
     * @param int $priority
     *
     * @return void
     */
    public static function push($args, $delay = 0, $priority = 0)
    {
        static $cache_bucket = [];

        $cache_key = hash('sha256', serialize($args)) . sprintf('%s|%s', $delay, $priority);

        if (isset($cache_bucket[$cache_key])) return;

        $cache_bucket[$cache_key] = true;

        self::get_instance()->queue->push(
            new QueueJob($args),
            $delay,
            $priority
        );
    }

    public function init_cron()
    {
        $attempts = apply_filters('fusewp_queue_manager_attempts', 2, $this);
        $interval = apply_filters('fusewp_queue_manager_interval', 1, $this);

        $this->queue->cron($attempts, $interval);
    }

    /**
     * Important this is not singleton.
     *
     * @return self
     */
    public static function get_instance()
    {
        return new self();
    }
}