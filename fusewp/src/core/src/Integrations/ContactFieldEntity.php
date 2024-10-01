<?php

namespace FuseWP\Core\Integrations;

class ContactFieldEntity
{
    const NUMBER_FIELD = 'number';

    const TEXT_FIELD = 'text';

    const DATE_FIELD = 'date';

    const DATETIME_FIELD = 'datetime';

    const MULTISELECT_FIELD = 'multiselect';

    const BOOLEAN_FIELD = 'boolean';

    public $id;

    public $name;

    public $data_type = 'text';

    /** @var bool */
    public $is_required = false;

    public function set_id($id)
    {
        $this->id = $id;

        return $this;
    }

    public function set_name($name)
    {
        $this->name = $name;

        return $this;
    }

    public function set_data_type($name)
    {
        $this->data_type = $name;

        return $this;
    }

    public function set_is_required($val = true)
    {
        $this->is_required = (bool)$val;

        return $this;
    }

    public function get_field_type_label()
    {
        $map = [
            self::DATE_FIELD        => esc_html__('Date', 'fusewp'),
            self::DATETIME_FIELD    => esc_html__('DateTime', 'fusewp'),
            self::TEXT_FIELD        => esc_html__('Text', 'fusewp'),
            self::NUMBER_FIELD      => esc_html__('Number', 'fusewp'),
            self::MULTISELECT_FIELD => esc_html__('Multiselect', 'fusewp'),
            self::BOOLEAN_FIELD     => esc_html__('Boolean', 'fusewp')
        ];

        return fusewpVar($map, $this->data_type, '');
    }
}