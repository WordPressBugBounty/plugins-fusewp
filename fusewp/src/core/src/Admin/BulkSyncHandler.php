<?php

namespace FuseWP\Core\Admin;

use FuseWP\Core\QueueManager\QueueManager;
use FuseWPVendor\PAnD as PAnD;

class BulkSyncHandler
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_bulk_sync_action']);

        add_action('fusewp_admin_notices', [$this, 'queue_admin_notices']);

        add_action('fusewp_queued_job_handler', [$this, 'handler']);

        add_action('admin_footer', [$this, 'js_script']);

        add_action('wp_ajax_fusewp_bulk_sync_status', [$this, 'bulk_sync_status_ajax_handler']);
    }

    public function get_pending_sync_jobs_count()
    {
        global $wpdb;

        $jobs_table = $wpdb->prefix . 'fusewp_queue_jobs';

        $count = $wpdb->get_var("SELECT COUNT(id) FROM {$jobs_table} WHERE job LIKE '%fwp_bulk_syncing%'");

        return absint($count);
    }

    public function queue_admin_notices()
    {
        $notices = [];

        if (fusewp_is_bulk_sync_flag_exists('fwp_bsp_completed')) {

            $notices[] = [
                'id'      => 'fwp_bsp_completed',
                'message' => esc_html__('The bulk-sync operation has been successfully completed.', 'fusewp'),
                'type'    => 'success',
                'expiry'  => 'forever'
            ];
        }

        if ( ! fusewp_is_bulk_sync_flag_exists('fwp_bsp_completed')) {
            // only do the below if sync completed flag is not set
            $pending_jobs_count = $this->get_pending_sync_jobs_count();

            if ($pending_jobs_count > 0) {

                $notices[] = [
                    'id'      => 'fwp_bsp_pending_jobs',
                    'message' => sprintf(
                        esc_html__('Bulk-sync in progress: %s records left to be processed. You will be notified upon completion.', 'fusewp'),
                        '<strong>' . $pending_jobs_count . '</strong>'
                    ),
                    'type'    => 'info',
                    'expiry'  => 'forever'
                ];
            }
        }

        foreach ($notices as $notice) {
            $notice_type = ! empty($notice['type']) ? $notice['type'] : 'info';
            $notice_id   = '';

            if (isset($notice['expiry'])) {
                $notice_id = sprintf('fwp_bulk_sync_notice_%s-%s', $notice['id'], $notice['expiry']);
                if ( ! PAnD::is_admin_notice_active($notice_id)) continue;
            }

            echo '<div data-dismissible="' . esc_attr($notice_id) . '" class="notice notice-' . $notice_type . ' is-dismissible">';
            echo "<p>" . $notice['message'] . "</p>";
            echo '</div>';
        }
    }

    public function handle_bulk_sync_action()
    {
        if (fusewpVarGET('fusewp_sync_action') == 'bulk_sync' && isset($_GET['id']) && current_user_can('manage_options')) {

            check_admin_referer('fusewp_sync_rule_bulk_sync');

            fusewp_set_time_limit();

            fusewp_delete_bulk_sync_flag('fwp_bsp_completed');
            fusewp_delete_bulk_sync_flag('fwp_bsp_pending_jobs');

            $sync_rule = fusewp_sync_get_rule(absint($_GET['id']));

            $source_id = fusewp_sync_get_real_source_id($sync_rule->source);

            $sync_rule_source_obj = fusewp_get_registered_sync_sources($source_id);

            $source_item_id = fusewp_sync_get_source_item_id($sync_rule->source);

            $page   = 1;
            $number = apply_filters('fusewp_bulk_sync_data_limit', 1000, $source_id, $source_item_id);
            $loop   = true;

            while ($loop === true) {

                $records = $sync_rule_source_obj->get_bulk_sync_data($source_item_id, $page, $number);

                if ( ! empty($records)) {

                    $record_count = count($records);

                    foreach ($records as $index => $record) {

                        QueueManager::push([
                            'action' => 'fwp_bulk_syncing',
                            // using single/double letter as array key to reduce the batch/process item payload
                            's'      => $source_id,
                            'si'     => $source_item_id,
                            'i'      => $index,
                            'r'      => $record
                        ]);
                    }

                    if ($record_count < $number || $record_count > $number) $loop = false;

                    $page++;

                } else {
                    $loop = false;
                }
            }

            wp_safe_redirect(FUSEWP_SYNC_SETTINGS_PAGE);
            exit;
        }
    }

    public function handler($item)
    {
        if (isset($item['action']) && $item['action'] == 'fwp_bulk_syncing') {

            if ( ! defined('FUSEWP_BULK_SYNC_PROCESS_TASK')) {
                define('FUSEWP_BULK_SYNC_PROCESS_TASK', 'true');
            }

            fusewp_get_registered_sync_sources($item['s'])->bulk_sync_handler($item);

            $throttle_seconds = max(0, apply_filters('fusewp_bulk_rate_throttle_seconds', 0, $item));

            // Let the server breathe a little.
            sleep($throttle_seconds);

            $count = $this->get_pending_sync_jobs_count();

            if ($count <= 1) {
                fusewp_set_bulk_sync_flag('fwp_bsp_completed');
            }
        }
    }

    public function bulk_sync_status_ajax_handler()
    {
        check_ajax_referer('fusewp_bulk_sync_status', 'csrf');

        if (current_user_can('manage_options')) {
            wp_send_json_success($this->get_pending_sync_jobs_count());
        }
    }

    public function js_script()
    {
        ?>
        <script>
            (function ($) {
                var interval_seconds = '<?php echo apply_filters('fusewp_fusewp_bulk_sync_status_interval_seconds', 5000) ?>';
                setTimeout(function () {
                    if ($('[data-dismissible="fwp_bulk_sync_notice_fwp_bsp_pending_jobs-forever"]').length > 0) {
                        setInterval(function () {
                            $.post(ajaxurl, {
                                    csrf: '<?php echo wp_create_nonce('fusewp_bulk_sync_status') ?>',
                                    action: "fusewp_bulk_sync_status",
                                }, function (response) {
                                    $('[data-dismissible="fwp_bulk_sync_notice_fwp_bsp_pending_jobs-forever"] p strong').text(response.data);

                                    if (response.data <= 0) {
                                        window.location.reload();
                                    }
                                }
                            );
                        }, interval_seconds);
                    }
                }, interval_seconds)
            })(jQuery);
        </script>
        <?php
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