<?php

use FuseWP\Core\Admin\Fields\AbstractFieldEntity;

defined('ABSPATH') || exit;

/** @global AbstractFieldEntity[] $fields */
/** @global array $db_destination */
/** @global string $wrapper_class */

foreach ($fields as $field) :
    $wrapper_class = ! empty($wrapper_class) ? " $wrapper_class" : '';
    $db_value = isset($db_destination) ? fusewpVar($db_destination, $field->db_field_id, '') : '';
    ?>
    <tr class="fusewp-table__row fusewp-sub-fields<?php echo esc_attr($wrapper_class) ?>">
        <td class="fusewp-table__col fusewp-table__col--label">

            <?php if ( ! empty($field->tooltip_description)) {
                printf('<span class="fusewp-help-tip hint--bottom hint--large" aria-label="%1$s"><span class="dashicons dashicons-editor-help"></span></span>', esc_html($field->tooltip_description));
            } ?>

            <label><?php echo esc_html($field->title); ?>
                <?php if ($field->is_required) : ?>
                    <span class="required">*</span>
                <?php endif; ?>
            </label>
        </td>

        <td class="fusewp-table__col fusewp-table__col--field fusewp-field-wrap">
            <?php $field->render($db_value); ?>
            <?php if ( ! empty($field->description)) : ?>
                <p class="fusewp-field-description"><?php echo wp_kses_post($field->description); ?></p>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach;
