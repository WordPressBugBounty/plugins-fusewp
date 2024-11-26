<?php

namespace FuseWP\Core\Admin\SettingsPage;

use FuseWP\Core\Core;
use FuseWP\Core\Integrations\AbstractSyncAction;

if ( ! class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class SyncList extends \WP_List_Table
{
    private $table;

    /** @var \wpdb */
    private $wpdb;

    public function __construct()
    {
        global $wpdb;

        $this->wpdb  = $wpdb;
        $this->table = Core::sync_rule_db_table();

        parent::__construct(array(
                'singular' => 'sync_rule',
                'plural'   => 'sync_rules',
                'ajax'     => false
            )
        );
    }

    public function get_sync_rule($per_page, $current_page = 1, $source = '')
    {
        $per_page     = absint($per_page);
        $current_page = absint($current_page);

        $offset = ($current_page - 1) * $per_page;
        $sql    = "SELECT * FROM $this->table";
        $args   = [];

        if ( ! empty($source)) {
            $sql    .= " WHERE source = %s";
            $args[] = $source;
        }

        $args[] = $per_page;

        $sql .= " ORDER BY id DESC";

        $sql .= " LIMIT %d";
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
     * @param string $source
     *
     * @return null|string
     */
    public function record_count($source = '')
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

    public static function add_url()
    {
        return esc_url_raw(add_query_arg(['fusewp_sync_action' => 'add'], FUSEWP_SYNC_SETTINGS_PAGE));
    }

    public static function edit_url($rule_id)
    {
        return add_query_arg(['fusewp_sync_action' => 'edit', 'id' => absint($rule_id)], FUSEWP_SYNC_SETTINGS_PAGE);
    }

    public static function delete_url($rule_id)
    {
        return wp_nonce_url(
            add_query_arg(['fusewp_sync_action' => 'delete', 'id' => absint($rule_id)], FUSEWP_SYNC_SETTINGS_PAGE),
            'fusewp_sync_rule_delete'
        );
    }

    public static function bulk_sync_url($rule_id)
    {
        return wp_nonce_url(
            add_query_arg(['fusewp_sync_action' => 'bulk_sync', 'id' => absint($rule_id)], FUSEWP_SYNC_SETTINGS_PAGE),
            'fusewp_sync_rule_bulk_sync'
        );
    }

    public function no_items()
    {
        ?>
        <div class="fusewp-wp-list-empty-state-wrapper">
            <div class="fusewp-wp-list-empty-state-inner">
                <h2><?php esc_html_e('Add Your First Sync Rule', 'fusewp'); ?></h2>
                <p><?php esc_html_e('Keep WordPress synchronize with external marketing integrations and third-party applications.', 'fusewp'); ?></p>
                <a href="<?php echo esc_url(self::add_url()); ?>" class="fusewp-btn"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('Add Sync Rule', 'fusewp'); ?>
                </a>
                <p class="fusewp-small">
                    <?php
                    printf(
                        __('New to FuseWP? Take a look at our <a href="%s" target="_blank">getting started guide</a>.', 'fusewp'),
                        'https://fusewp.com/section/user-sync/'
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     *  Associative array of columns
     *
     * @return array
     */
    public function get_columns()
    {
        $columns = array(
            'cb'           => '<input type="checkbox" />',
            'source'       => esc_html__('Source', 'fusewp'),
            'destinations' => esc_html__('Destinations', 'fusewp'),
            'status'       => esc_html__('Status', 'fusewp')
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
            '<input type="checkbox" name="sync_rule_id[]" value="%s" />', esc_attr($item['id'])
        );
    }

    /**
     * @param array $item
     *
     * @return string
     */
    public function column_source($item)
    {
        $source = esc_html__('[Not Set]', 'fusewp');

        if ( ! empty($item['source'])) {

            $source_item_id = fusewp_sync_get_source_item_id($item['source']);

            $source_obj = fusewp_get_registered_sync_sources(fusewp_sync_get_real_source_id($item['source']));

            if (isset($source_obj->title)) {
                $source            = $source_obj->title;
                $source_item_label = fusewpVar($source_obj->get_source_items(), $source_item_id, '');

                if ( ! empty($source_item_label)) {
                    $source = sprintf('%s &mdash; %s', $source_item_label, $source);
                }
            }
        }

        $rule_id       = absint($item['id']);
        $edit_url      = esc_url(self::edit_url($rule_id));
        $delete_url    = esc_url(self::delete_url($rule_id));
        $bulk_sync_url = esc_url(self::bulk_sync_url($rule_id));

        $actions = array(
            'edit'      => sprintf("<a href='%s'>%s</a>", $edit_url, esc_attr__('Edit', 'fusewp')),
            'bulk-sync' => sprintf("<a class='fusewp-confirm-bulk-sync' href='%s'>%s</a>", $bulk_sync_url, esc_attr__('Bulk Sync', 'fusewp')),
            'delete'    => sprintf("<a class='fusewp-confirm-delete' href='%s'>%s</a>", $delete_url, esc_attr__('Delete', 'fusewp'))
        );

        $name = '<strong><a href="' . esc_attr($edit_url) . '">' . esc_html($source) . '</a></strong>';


        return $name . $this->row_actions($actions);
    }

    /**
     * @param array $item
     *
     * @return string
     */
    public function column_destinations($item)
    {
        $destinations = esc_html__('[Not Set]', 'fusewp');

        if ( ! empty($item['destinations'])) {

            $destinations_args = json_decode($item['destinations'], true);

            if (is_array($destinations_args) && ! empty($destinations_args)) {

                $source_obj = fusewp_get_registered_sync_sources(fusewp_sync_get_real_source_id($item['source']));

                $destinations = '';

                foreach ($destinations_args as $destination_args) {

                    $integration = fusewp_get_registered_sync_integrations($destination_args['integration']);

                    $destination_email_list = '';

                    if (is_object($integration) && method_exists($integration, 'get_email_list')) {

                        $destination_email_list = fusewpVar(
                            $integration->get_email_list(),
                            fusewpVar($destination_args, AbstractSyncAction::EMAIL_LIST_FIELD_ID, ''),
                            ''
                        );
                    }

                    if ( ! empty($destination_args['destination_item'])) {
                        $destinations .= sprintf(
                            '<p><strong>%s:</strong> %s %s</p>',
                            is_object($source_obj) && method_exists($source_obj, 'get_destination_items') ? fusewpVar($source_obj->get_destination_items(), $destination_args['destination_item'], '') : '',
                            fusewpVarObj($integration, 'title', ''),
                            ! empty($destination_email_list) ? '(' . $destination_email_list . ')' : ''
                        );
                    }
                }
            }
        }


        return $destinations;
    }

    public function column_status($item)
    {
        printf(
            '<button type="button" class="%s" data-sync-id="%s" data-fusewp-switch="%s">%s</button>',
            'fusewp-switch fusewp-toggle-sync-status',
            esc_attr($item['id']),
            esc_attr($item['status']),
            esc_html__('Toggle Status', 'fusewp')
        );
    }

    /**
     * Returns an associative array containing the bulk action
     *
     * @return array
     */
    public function get_bulk_actions()
    {
        $actions = array(
            'bulk-delete' => esc_html__('Delete', 'fusewp'),
        );

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
        $per_page     = $this->get_items_per_page('sync_rules_per_page', 10);
        $current_page = $this->get_pagenum();
        $total_items  = self::record_count();
        $this->set_pagination_args([
            'total_items' => $total_items, //WE have to calculate the total number of items
            'per_page'    => $per_page //WE have to determine how many items to show on a page
        ]);

        $this->items = $this->get_sync_rule($per_page, $current_page, '');
    }

    public function current_action()
    {
        if (isset($_REQUEST['filter_action']) && ! empty($_REQUEST['filter_action'])) {
            return false;
        }

        if (isset($_REQUEST['action']) && -1 != $_REQUEST['action']) {
            return $_REQUEST['action'];
        }

        if (isset($_REQUEST['fusewp_sync_action']) && -1 != $_REQUEST['fusewp_sync_action']) {
            return $_REQUEST['fusewp_sync_action'];
        }

        return false;
    }

    public function process_actions()
    {
        // Bail if user is not an admin or without admin privileges.
        if ( ! current_user_can('manage_options')) return;

        if ('delete' === $this->current_action()) {

            check_admin_referer('fusewp_sync_rule_delete');

            fusewp_sync_delete_rule(intval($_GET['id']));

            fusewp_do_admin_redirect(FUSEWP_SYNC_SETTINGS_PAGE);
        }

        // Detect when a bulk action is being triggered...
        if ('bulk-delete' == $this->current_action()) {
            check_admin_referer('bulk-' . $this->_args['plural']);
            $rule_ids = array_map('absint', $_POST['sync_rule_id']);

            foreach ($rule_ids as $rule_id) {
                fusewp_sync_delete_rule($rule_id);
            }
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