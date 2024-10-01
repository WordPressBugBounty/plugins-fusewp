<?php

use FuseWP\Core\Admin\SettingsPage\SyncPage;

$sync_rule_data = fusewp_sync_get_rule(absint(fusewpVarGET('id')));

if (fusewpVarGET('fusewp_sync_action') == 'edit' && ! is_object($sync_rule_data)) {
    fusewp_content_http_redirect(FUSEWP_SYNC_SETTINGS_PAGE);

    return;
}

$GLOBALS['fusewp_sync_rule_source_id']      = fusewp_sync_get_real_source_id(fusewpVarObj($sync_rule_data, 'source'));
$GLOBALS['fusewp_sync_rule_source_item_id'] = fusewp_sync_get_source_item_id(fusewpVarObj($sync_rule_data, 'source'));

add_action('add_meta_boxes', function () use ($sync_rule_data) {

    $metabox_classes_filter = function ($classes) {
        $classes[] = 'fusewp-metabox';
        $classes[] = 'no-drag';

        return $classes;
    };

    add_filter("postbox_classes_fusewpsync_fusewp-sync-source-content", $metabox_classes_filter);
    add_filter("postbox_classes_fusewpsync_fusewp-sync-destination-content", $metabox_classes_filter);
    add_filter("postbox_classes_fusewpsync_fusewp-sync-pro-features", $metabox_classes_filter);
    add_filter("postbox_classes_fusewpsync_submitdiv", $metabox_classes_filter);

    add_meta_box(
        'fusewp-sync-source-content',
        esc_html__('Source', 'fusewp'),
        function () use ($sync_rule_data) {
            require dirname(__FILE__) . '/source.php';
        },
        'fusewpsync'
    );

    add_meta_box(
        'fusewp-sync-destination-content',
        esc_html__('Destinations', 'fusewp'),
        function () use ($sync_rule_data) {
            require dirname(__FILE__) . '/destination.php';
        },
        'fusewpsync'
    );

    add_meta_box(
        'submitdiv',
        __('Publish', 'fusewp'),
        function () use ($sync_rule_data) {
            require dirname(__FILE__) . '/sidebar.php';
        },
        'fusewpsync',
        'sidebar'
    );

    if ( ! defined('FUSEWP_DETACH_LIBSODIUM')) {

        add_meta_box(
            'fusewp-sync-pro-features',
            sprintf(
                esc_html__('Upgrade to FuseWP Premium %sUpgrade Now%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=pro_sync_features_metabox_header">', '</a>'
            ),
            function () {
                require dirname(__FILE__) . '/view.pro-upsell.php';
            },
            'fusewpsync'
        );
    }
});

do_action('add_meta_boxes', 'fusewpsync', new WP_Post(new stdClass()));

$title = SyncPage::get_instance()->admin_page_title();

?>
<div class="wrap fwpview">
    <h2><?php echo esc_html($title); ?></h2>
    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-2">
            <form method="post">
                <div id="postbox-container-1" class="postbox-container">
                    <?php do_meta_boxes('fusewpsync', 'sidebar', ''); ?>
                </div>
                <div id="postbox-container-2" class="postbox-container">
                    <?php do_meta_boxes('fusewpsync', 'advanced', ''); ?>
                </div>
            </form>
        </div>
        <br class="clear">
    </div>
</div>