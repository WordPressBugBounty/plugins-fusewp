<?php

namespace FuseWP\Core;

class AjaxHandler
{
    public function __construct()
    {
        // fusewp_event => nopriv
        $ajax_events = array(
            'sync_get_source_data'        => false,
            'sync_get_integration_fields' => false,
            'sync_get_list_fields'        => false,
            'toggle_sync_status'          => false
        );

        foreach ($ajax_events as $ajax_event => $nopriv) {
            add_action('wp_ajax_fusewp_' . $ajax_event, array($this, $ajax_event));

            if ($nopriv) {
                // FuseWP AJAX can be used for frontend ajax requests.
                add_action('wp_ajax_nopriv_fusewp_' . $ajax_event, array($this, $ajax_event));
            }
        }
    }

    public function sync_get_source_data()
    {
        check_ajax_referer('fusewp_csrf', 'csrf');

        if (current_user_can('manage_options')) {

            if (empty($_POST['source'])) {
                wp_send_json_error(esc_html__('No source selected.', 'fusewp'));
            }

            $source = fusewp_get_registered_sync_sources(fusewp_sync_get_real_source_id(sanitize_text_field($_POST['source'])));

            if ($source && method_exists($source, 'get_destination_items')) {
                $source_items             = $source->get_source_items();
                $source_items_fields_html = '';

                if (false !== $source_items) {

                    $source_items_fields_html = fusewp_render_view('sync/source-item', [
                        'source_id'           => $source->id,
                        'source_items'        => $source_items,
                        'wrapper_class'       => 'fusewp-source-items-fields',
                        'source_with_item_id' => sanitize_text_field($_POST['source_with_item_id']),
                    ]);
                }

                wp_send_json_success([
                    'source_items_field'     => $source_items_fields_html,
                    'destination_items'      => $source->get_destination_items(),
                    'rule_information'       => $source->get_rule_information(),
                    'destination_item_label' => $source->get_destination_item_label()
                ]);
            }

            wp_send_json_error(esc_html__('No destination item in selected source.', 'fusewp'));
        }
    }

    public function sync_get_integration_fields()
    {
        check_ajax_referer('fusewp_csrf', 'csrf');

        if (current_user_can('manage_options')) {

            if (empty($_POST['integration'])) {
                wp_send_json_error(esc_html__('Selected integration not found.', 'fusewp'));
            }

            $integration = fusewp_get_registered_sync_integrations(sanitize_text_field($_POST['integration']));

            $synAction = $integration->get_sync_action();

            if ($synAction && method_exists($synAction, 'get_fields')) {

                $setting_fields_html = '';

                $synActionFields = $synAction->get_fields(absint($_POST['index']));

                if (is_array($synActionFields) && ! empty($synActionFields)) {
                    $setting_fields_html = fusewp_render_view('action-fields', ['fields' => $synActionFields]);
                }

                wp_send_json_success([
                    'integration_fields' => $setting_fields_html
                ]);
            }

            wp_send_json_error(esc_html__('No integration field found.', 'fusewp'));
        }
    }

    public function sync_get_list_fields()
    {
        check_ajax_referer('fusewp_csrf', 'csrf');

        if (current_user_can('manage_options')) {

            if (empty($_POST['integration'])) {
                wp_send_json_error(esc_html__('Selected integration not found.', 'fusewp'));
            }

            if (empty($_POST['list_id'])) {
                wp_send_json_error(esc_html__('Selected integration list ID not found.', 'fusewp'));
            }

            $integration = fusewp_get_registered_sync_integrations(sanitize_text_field($_POST['integration']));

            $list_id = sanitize_text_field($_POST['list_id'] ?? '');

            $source = sanitize_text_field($_POST['source'] ?? '');

            $synAction = $integration->get_sync_action();

            if ($synAction && method_exists($synAction, 'get_list_fields')) {

                $setting_fields_html = '';

                $synActionIntegrationFields = $synAction->get_list_fields($list_id, absint($_POST['index']));

                if (is_array($synActionIntegrationFields) && ! empty($synActionIntegrationFields)) {
                    $setting_fields_html = fusewp_render_view('action-fields', [
                        'fields'         => $synActionIntegrationFields,
                        'wrapper_class'  => 'fusewp-list-sub-fields',
                        'db_destination' => apply_filters(
                            'fusewp_sync_integration_list_fields_default_data',
                            $synAction->get_list_fields_default_data(),
                            $source,
                            $synAction,
                            $integration
                        )
                    ]);
                }

                wp_send_json_success([
                    'integration_list_fields' => $setting_fields_html
                ]);
            }

            wp_send_json_error(esc_html__('No integration field found.', 'fusewp'));
        }
    }

    public function toggle_sync_status()
    {
        if ( ! current_user_can('manage_options')) die;

        $sync_rule_id = intval(fusewpVarPOST('sync_rule_id'));

        $new_state = sanitize_text_field(fusewpVarPOST('new_state'));

        if ( ! $sync_rule_id || ! $new_state) die;

        fusewp_sync_update_rule_status($sync_rule_id, $new_state);

        wp_send_json_success();
    }

    /**
     * @return AjaxHandler
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