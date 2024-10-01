<?php

namespace FuseWP\Core\Admin\Fields;

class Textarea extends AbstractFieldEntity
{
    public function render($db_value = '')
    {
        ?>
        <textarea
            name="<?php echo esc_attr($this->field_name); ?>"
            class="fusewp-field fusewp-field--type-textarea <?php echo esc_attr(implode(' ', $this->classes)) ?>"
            placeholder="<?php echo esc_attr($this->placeholder) ?>"
            <?php echo $this->is_required ? 'required' : ''; ?>
        ><?php echo esc_textarea($db_value); ?></textarea>
        <?php
    }
}