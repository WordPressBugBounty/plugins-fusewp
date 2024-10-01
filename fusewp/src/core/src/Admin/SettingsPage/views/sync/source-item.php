<?php

defined('ABSPATH') || exit;

/**
 * @var string $source_id
 * @var string $source_with_item_id
 * @var mixed $source_items
 */
?>

<tr class="fusewp-table__row fusewp-source-item-select-wrapper" data-type="select" data-required="1">
    <td class="fusewp-table__col fusewp-table__col--label">
        <label><?php esc_html_e('Source Item', 'fusewp'); ?> <span class="required">*</span></label>
    </td>
    <td class="fusewp-table__col fusewp-table__col--field">
        <select name="fusewp_sync_source_item" class="fusewp-field fusewp-source-item-select">
            <option value="">&mdash;&mdash;&mdash;</option>
            <?php foreach ($source_items as $item_id => $item) : $source_item_id = sprintf('%s|%s', $source_id, $item_id); ?>
                <option value="<?php echo esc_attr($source_item_id) ?>" <?php selected($source_with_item_id, $source_item_id) ?>><?php echo esc_attr($item) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="fusewp-trigger-description">
            <p class="fusewp-field-description"><?php esc_html_e('Select the corresponding item for the source selected above.', 'fusewp'); ?></p>
        </div>
    </td>
</tr>
