<?php

namespace FuseWP\Core\Admin\SettingsPage;

use FuseWP\Core\Core;

if ( ! class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class SyncLogList extends \WP_List_Table
{
    private $table;

    /** @var \wpdb */
    private $wpdb;

    public function __construct()
    {
        global $wpdb;

        $this->wpdb  = $wpdb;
        $this->table = Core::sync_log_db_table();

        parent::__construct(array(
                'singular' => esc_html__('sync_log', 'fusewp'),
                'plural'   => esc_html__('sync_logs', 'fusewp'),
                'ajax'     => false
            )
        );
    }

    /** @todo added filtering by search => [integration=id, q=''] */
    public function get_sync_logs($per_page, $current_page = 1, $search = [])
    {
        $per_page     = absint($per_page);
        $current_page = absint($current_page);

        $offset = ($current_page - 1) * $per_page;
        $sql    = "SELECT * FROM $this->table";
        $args   = [];

        /** @todo */
        if ( ! empty($search)) {
            $sql    .= " WHERE source = %s";
            $args[] = $source;
        }

        $sql .= " ORDER BY id DESC";

        $args[] = $per_page;
        $sql    .= " LIMIT %d";

        if ($current_page > 1) {
            $args[] = $offset;
            $sql    .= "  OFFSET %d";
        }

        $result = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $args),
            'ARRAY_A'
        );

        return $result;
    }

    /**
     * Returns the count of records in the database.
     *
     * @param array $search
     *
     * @return null|string
     */
    public function record_count($search = [])
    {
        $sql = "SELECT COUNT(*) FROM $this->table WHERE 1 = %d";

        $args[] = 1;

        if ( ! empty($source)) {
            $sql    .= "  AND source = %s";
            $args[] = $source;
        }

        return $this->wpdb->get_var(
            $this->wpdb->prepare($sql, $args)
        );
    }

    public static function delete_url($log_id)
    {
        return wp_nonce_url(
            add_query_arg(['fusewp_sync_logs_action' => 'delete', 'id' => absint($log_id)], FUSEWP_SYNC_LOGS_SETTINGS_PAGE),
            'fusewp_sync_log_delete'
        );
    }

    public function no_items()
    {
        esc_html_e('No logs found.', 'fusewp');
    }

    /**
     * @return array
     */
    public function get_columns()
    {
        $columns = array(
            'cb'            => '<input type="checkbox" />',
            'integration'   => esc_html__('Integration', 'fusewp'),
            'error_message' => esc_html__('Error Message', 'fusewp'),
            'date'          => esc_html__('Date', 'fusewp')
        );

        return $columns;
    }

    /**
     * Render the bulk edit checkbox
     *
     * @param array $item
     *
     * @return string
     */
    function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="sync_log_id[]" value="%s" />', esc_attr($item['id'])
        );
    }

    /**
     * @param array $item
     *
     * @return string
     */
    public function column_integration($item)
    {
        $output = '&mdash;';

        if ( ! empty($item['integration'])) {
            $integration = fusewp_get_registered_sync_integrations($item['integration']);

            if (isset($integration->title)) {
                $output = $integration->title;
            }
        }

        $log_id = absint($item['id']);

        $actions = [
            'delete' => sprintf('<a class="fusewp-confirm-delete" href="%s">%s</a>', self::delete_url($log_id), esc_attr__('Delete', 'fusewp'))
        ];

        return $output . $this->row_actions($actions);
    }

    public function column_error_message($item)
    {
        $error = fusewpVar($item, 'error_message', '');
        $html  = sprintf('<textarea readonly>%s</textarea>', esc_textarea($error));

        return $html;
    }

    public function column_date($item)
    {
        return wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            fusewp_strtotime_utc($item['date'])
        );
    }

    /**
     * Returns an associative array containing the bulk action
     *
     * @return array
     */
    public function get_bulk_actions()
    {
        $actions = [
            'bulk-delete'     => esc_html__('Delete', 'fusewp'),
            'bulk-delete-all' => esc_html__('Delete All Log', 'fusewp'),
        ];

        return $actions;
    }

    /**
     * Handles data query and filter, sorting, and pagination.
     *
     * @param string $source
     */
    public function prepare_items($source = '')
    {
        $this->_column_headers = $this->get_column_info();
        /** Process bulk action */
        $this->process_actions();
        $per_page     = $this->get_items_per_page('sync_logs_per_page', 10);
        $current_page = $this->get_pagenum();
        $total_items  = self::record_count();
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page
        ]);

        $this->items = $this->get_sync_logs($per_page, $current_page, '');
    }

    public function current_action()
    {
        if (isset($_REQUEST['filter_action']) && ! empty($_REQUEST['filter_action'])) {
            return false;
        }

        if (isset($_REQUEST['action']) && -1 != $_REQUEST['action']) {
            return $_REQUEST['action'];
        }

        if (isset($_REQUEST['fusewp_sync_logs_action']) && -1 != $_REQUEST['fusewp_sync_logs_action']) {
            return $_REQUEST['fusewp_sync_logs_action'];
        }

        return false;
    }

    public function process_actions()
    {
        // Bail if user is not an admin or without admin privileges.
        if ( ! current_user_can('manage_options')) return;

        if ('delete' === $this->current_action()) {

            check_admin_referer('fusewp_sync_log_delete');

            fusewp_delete_error_log(intval($_GET['id']));

            fusewp_do_admin_redirect(FUSEWP_SYNC_LOGS_SETTINGS_PAGE);
        }

        // Detect when a bulk action is being triggered...
        if ('bulk-delete' == $this->current_action()) {

            check_admin_referer('bulk-' . $this->_args['plural']);

            $log_ids = array_map('absint', $_POST['sync_log_id']);

            foreach ($log_ids as $log_id) {
                fusewp_delete_error_log($log_id);
            }
        }

        // Detect when a bulk action is being triggered...
        if ('bulk-delete-all' == $this->current_action()) {

            check_admin_referer('bulk-' . $this->_args['plural']);

            fusewp_delete_all_error_log();
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