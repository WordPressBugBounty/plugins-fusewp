<?php

namespace FuseWP\Core\Admin\BulkSync;

use \PAnD;

class BulkSyncHandler
{
    /**
     * @var BulkSyncBGProcess
     */
    protected $bulkSyncBGProcess;

    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'init']);

        add_action('admin_init', [$this, 'handle_bulk_sync_action']);

        add_action('fusewp_admin_notices', [$this, 'queue_admin_notices']);
    }

    public function init()
    {
        $this->bulkSyncBGProcess = new BulkSyncBGProcess();
    }

    public function queue_admin_notices()
    {
        $notices = [];

        if (fusewp_is_bulk_sync_flag_exists('fwp-bulk-sync-queue-started')) {

            fusewp_delete_bulk_sync_flag('fwp-bulk-sync-queue-started');

            $source = fusewp_get_registered_sync_sources(fusewpVarGET('fwp-source', ''));

            $notices[] = [
                'id'      => 'fwp-bulk-sync-queue-started',
                'message' => sprintf(
                    esc_html__('A background task has been created to bulk-sync the selected %s rule. You will be notified upon completion of the process.', 'fusewp'),
                    $source->title ?? ''
                )
            ];

            $this->bulkSyncBGProcess->dispatch();
        }

        if (fusewp_is_bulk_sync_flag_exists('fwp_bsp_save_failed')) {

            $notices[] = [
                'id'      => 'fwp_bsp_save_failed',
                'message' => esc_html__('Unable to save background task for bulk-sync operation. Please try again.', 'fusewp'),
                'type'    => 'error',
                'expiry'  => 'forever'
            ];
        }

        if (fusewp_is_bulk_sync_flag_exists('fwp_bsp_dispatch_failed')) {

            $notices[] = [
                'id'      => 'fwp_bsp_dispatch_failed',
                'message' => esc_html__('Unable to dispatch background task for bulk-sync operation. Please try again.', 'fusewp'),
                'type'    => 'error',
                'expiry'  => 'forever'
            ];
        }

        if (fusewp_is_bulk_sync_flag_exists('fwp_bsp_completed')) {

            $notices[] = [
                'id'      => 'fwp_bsp_completed',
                'message' => esc_html__('The bulk-sync operation has been completed successfully.', 'fusewp'),
                'type'    => 'success',
                'expiry'  => 'forever'
            ];
        }

        foreach ($notices as $notice) {

            $notice_type = ! empty($notice['type']) ? $notice['type'] : 'info';

            $notice_id = '';

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

            fusewp_delete_bulk_sync_flag('fwp_bsp_save_failed');
            fusewp_delete_bulk_sync_flag('fwp_bsp_dispatch_failed');
            fusewp_delete_bulk_sync_flag('fwp_bsp_completed');

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

                        $this->bulkSyncBGProcess->push_to_queue([
                            // using single/double letter as array key to reduce the batch/process item payload
                            's'  => $source_id,
                            'si' => $source_item_id,
                            'i'  => $index,
                            'r'  => $record
                        ]);
                    }

                    // bgprocess is dispatched by queue_admin_notices
                    $this->bulkSyncBGProcess->save();

                    if ($record_count < $number || $record_count > $number) $loop = false;

                    $page++;

                } else {
                    $loop = false;
                }
            }

            fusewp_set_bulk_sync_flag('fwp-bulk-sync-queue-started');

            wp_safe_redirect(add_query_arg([
                'fwp-source' => $source_id,
                'message'    => 'fwp-bulk-sync-queue-started'
            ], FUSEWP_SYNC_SETTINGS_PAGE));
            exit;
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