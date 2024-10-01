<?php

namespace FuseWP\Core\Admin\Fields;

interface FieldInterface
{
    /**
     * @param mixed $db_value
     */
    public function render($db_value = '');
}