<?php

namespace FuseWP\Core\Admin\Fields;

class Text extends AbstractFieldEntity
{
    protected $type = 'text';

    public function render($db_value = '')
    {
        ?>
        <input type="<?php echo esc_attr($this->type) ?>"
               name="<?php echo esc_attr($this->field_name); ?>"
               value="<?php echo esc_attr($db_value); ?>"
               class="fusewp-field fusewp-field--type-input-<?php echo  $this->type ?> <?php echo esc_attr(implode(' ', $this->classes)) ?>"
               placeholder="<?php echo esc_attr($this->placeholder) ?>"
            <?php echo $this->is_required ? 'required' : ''; ?>
        >
        <?php
    }
}