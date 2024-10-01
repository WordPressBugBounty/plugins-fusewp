<?php

/** @var mixed $sync_rule_data */
$db_source_raw = fusewpVarObj($sync_rule_data, 'source', '');
$db_source     = fusewp_sync_get_real_source_id($db_source_raw);

$source_items = null;

if ($db_source) {
    $source_items = fusewp_get_registered_sync_sources($db_source)->get_source_items();
}

$sources = fusewp_get_registered_sync_sources();
?>
<table class="fusewp-table fusewp-source-table">
    <tbody>
    <tr class="fusewp-table__row" data-type="select" data-required="1">
        <td class="fusewp-table__col fusewp-table__col--label">
            <label><?php esc_html_e('Source', 'fusewp'); ?> <span class="required">*</span></label>
        </td>
        <td class="fusewp-table__col fusewp-table__col--field">
            <select name="fusewp_sync_source" class="fusewp-field fusewp-source-select">
                <option value="">&mdash;&mdash;&mdash;</option>
                <?php foreach ($sources as $source) : ?>
                    <option value="<?php echo esc_attr($source->id) ?>" <?php selected($db_source,
                        $source->id) ?>><?php echo esc_html($source->title); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="fusewp-trigger-description">
                <p class="fusewp-field-description">
                    <?php esc_html_e('Select the user role, membership plan, form or source plugin to synchronize from.', 'fusewp'); ?>
                </p>
            </div>
        </td>
    </tr>

    <?php if ($db_source_raw && is_array($source_items) && ! empty($source_items)):
        echo fusewp_render_view('sync/source-item', [
            'source_id'           => $db_source,
            'source_items'        => $source_items,
            'wrapper_class'       => 'fusewp-source-items-fields',
            'source_with_item_id' => $db_source_raw,
        ]);
    endif;
    ?>
    </tbody>
</table>
