<?php

namespace FuseWP\Core\Sync\Sources;

class SyncQueueHandler
{
    public function __construct()
    {
        add_action('fusewp_queued_job_handler', [$this, 'handler'], 20);
    }

    public function handler($item)
    {
        if (isset($item['action']) && in_array($item['action'], ['subscribe_user', 'unsubscribe_user'])) {

            $integration = fusewp_get_registered_sync_integrations($item['integration']);
            $sync_action = $integration->get_sync_action();
            $list_id     = $item['list_id'];

            $GLOBALS['fusewp_sync_source_id']             = $item['source_id'];
            $GLOBALS['fusewp_sync_destination'][$list_id] = $item['destination'];
            $GLOBALS['fusewp_sync_execution_rule_id']     = $item['rule_id'];
            $GLOBALS['fusewp_sync_destination_extras']    = $item['extras'];

            $args = [$item['list_id'], $item['email_address']];

            if ($item['action'] == 'subscribe_user') {
                $args[] = $item['mappingUserDataEntity'];
                $args[] = AbstractSyncSource::filter_out_fusewpEmail_from_custom_fields_array(
                    fusewpVar($item['destination'], $sync_action::CUSTOM_FIELDS_FIELD_ID, [])
                );
                $args[] = fusewpVar($item['destination'], $sync_action::TAGS_FIELD_ID, '');
                $args[] = $item['old_email_address'] ?? '';
            }

            if (defined('FUSEWP_BULK_SYNC_PROCESS_TASK')) {

                $cache_expiration = apply_filters('fusewp_bulk_sync_processed_cache_expiration', DAY_IN_SECONDS);

                $cache_key = hash("sha256", serialize($item));

                $processed_bucket = get_option('fusewp_bulk_sync_processed_cache', []);

                if ( ! empty($processed_bucket[$cache_key])) {

                    $last_processed_time = absint($processed_bucket[$cache_key]);

                    if ((time() - $last_processed_time) < $cache_expiration) return;
                }

                $is_success = call_user_func_array([$sync_action, $item['action']], $args);

                if ($is_success) {

                    $processed_bucket[$cache_key] = time();
                    // save bulk-sync last processed date
                    $processed_bucket['last_processed'] = time();

                    update_option('fusewp_bulk_sync_processed_cache', $processed_bucket);
                }

            } else {

                call_user_func_array([$sync_action, $item['action']], $args);
            }
        }
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}