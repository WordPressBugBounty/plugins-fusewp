<?php

use FuseWP\Core\Integrations\AbstractSyncAction;

defined('ABSPATH') || exit;

/**
 * @var int $index
 * @var string $source_item_id
 * @var string $sync_integration_id
 * @var mixed $db_destinations
 */

$db_destination = fusewpVar(array_values($db_destinations), --$index);

$source = fusewp_get_registered_sync_sources($source_item_id);

$destination_items = [];

if ($source && method_exists($source, 'get_destination_items')) {
    $destination_items = $source->get_destination_items();
}

$synActionFields = [];

$synActionIntegrationFields = [];

$sync_integration = fusewp_get_registered_sync_integrations($sync_integration_id);

if ($sync_integration && method_exists($sync_integration, 'get_sync_action')) {
    $sync_action = $sync_integration->get_sync_action();

    $synActionFields = $sync_action->get_fields($index);

    $synActionIntegrationFields = $sync_action->get_list_fields(
        fusewpVar($db_destination, AbstractSyncAction::EMAIL_LIST_FIELD_ID),
        $index
    );
}

?>

<div data-index="<?php echo $index ?>" class="fusewp-action">

    <div class="fusewp-action__header">
        <div class="row-options">
            <a class="fusewp-edit-action" href="#"><?php esc_html_e('Edit', 'fusewp'); ?></a>
            <a class="fusewp-delete-action" href="#"><?php esc_html_e('Delete', 'fusewp'); ?></a>
        </div>
        <h4 class="action-title"><?php echo fusewpVar($destination_items, fusewpVar($db_destination, 'destination_item'), esc_html__('New Destination', 'fusewp')); ?></h4>
    </div>

    <div class="fusewp-action__fields">
        <table class="fusewp-table">
            <tbody>
            <tr class="fusewp-table__row">
                <td class="fusewp-table__col fusewp-table__col--label">
                    <label><?php echo esc_html($source->get_destination_item_label()) ?> <span class="required">*</span></label>
                </td>
                <td class="fusewp-table__col fusewp-table__col--field">
                    <select name="fusewp_sync_destinations[<?php echo $index ?>][destination_item]" class="fusewp-field fusewp-field--type-select fusewp-action-select" required>
                        <option value="">&mdash;&mdash;&mdash;</option>
                        <?php foreach ($destination_items as $destination_key => $destination_value) :
                            $disabled_flag = strpos($destination_key, 'fusewp_disabled') !== false ? ' disabled' : ''; ?>
                            <option<?php echo $disabled_flag; ?> value="<?php echo esc_attr($destination_key) ?>"<?php selected($destination_key, fusewpVar($db_destination, 'destination_item')) ?>><?php echo esc_html($destination_value) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr class="fusewp-table__row">
                <td class="fusewp-table__col fusewp-table__col--label">
                    <label><?php esc_html_e('Select Integration', 'fusewp'); ?>
                        <span class="required">*</span>
                    </label>
                </td>
                <td class="fusewp-table__col fusewp-table__col--field">
                    <select name="fusewp_sync_destinations[<?php echo $index ?>][integration]" class="fusewp-field fusewp-field--type-select fusewp-integration-select" required>
                        <option value="">&mdash;&mdash;&mdash;</option>
                        <?php foreach (fusewp_get_registered_sync_integrations('', true) as $integration) : ?>
                            <option value="<?php echo esc_attr($integration->id) ?>"<?php selected($integration->id, fusewpVar($db_destination, 'integration')) ?>><?php echo esc_html($integration->title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <?php echo fusewp_render_view('action-fields', [
                'fields'         => $synActionFields,
                'db_destination' => $db_destination
            ]);

            echo fusewp_render_view('action-fields', [
                'wrapper_class'  => 'fusewp-list-sub-fields',
                'fields'         => $synActionIntegrationFields,
                'db_destination' => $db_destination
            ]); ?>

            </tbody>
        </table>
    </div>
</div>
