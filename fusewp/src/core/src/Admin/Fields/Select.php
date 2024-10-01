<?php

namespace FuseWP\Core\Admin\Fields;

class Select extends AbstractFieldEntity
{
    public $is_multiple = false;

    public function set_is_multiple()
    {
        $this->is_multiple = true;

        return $this;
    }

    public function render($db_value = '')
    {
        $field_name = sprintf('%s%s', $this->field_name, $this->is_multiple ? '[]' : '');

        if ($this->is_multiple) $this->classes[] = 'fusewp-field-select2';

        ?>
        <select class="fusewp-field fusewp-field--type-select <?php echo implode(' ', $this->classes) ?>" name="<?php echo esc_attr($field_name); ?>"
            <?php echo $this->is_multiple ? 'multiple' : ''; ?>
            <?php echo $this->is_required ? 'required' : ''; ?>
        >
            <?php if ( ! empty($this->placeholder)): ?>
                <option value=""><?php echo esc_html($this->placeholder); ?></option>
            <?php endif; ?>

            <?php foreach ($this->options as $opt_name => $opt_value): ?>
                <?php if (is_array($opt_value)): ?>
                    <optgroup label="<?php echo esc_attr($opt_name) ?>">
                        <?php foreach ($opt_value as $opt_sub_name => $opt_sub_value): ?>
                            <option value="<?php echo esc_attr($opt_sub_name); ?>" <?php fusewp_selected($db_value, $opt_sub_name); ?>>
                                <?php echo esc_html($opt_sub_value); ?>
                            </option>
                        <?php endforeach ?>
                    </optgroup>
                <?php else: ?>
                    <option value="<?php echo esc_attr($opt_name); ?>" <?php fusewp_selected($db_value, $opt_name); ?>>
                        <?php echo esc_html($opt_value); ?>
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <?php
    }
}