<?php

namespace FuseWP\Core\Admin\Fields;

class Custom extends AbstractFieldEntity
{
    public $content = '';

    public function set_content($content)
    {
        $this->content = $content;

        return $this;
    }

    public function render($db_value = '')
    {
        ?>
        <div class="fusewp-field fusewp-field--type-custom <?php echo implode(' ', $this->classes) ?>">
            <?php echo is_callable($this->content) ? call_user_func($this->content) : $this->content; ?>
        </div>
        <?php
    }
}