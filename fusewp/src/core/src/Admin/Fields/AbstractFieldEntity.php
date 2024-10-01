<?php

namespace FuseWP\Core\Admin\Fields;

abstract class AbstractFieldEntity implements FieldInterface
{
    public $db_field_id;

    public $field_name;

    public $title;

    public $classes = [];

    public $options = [];

    public $is_required;

    public $placeholder = '';

    public $description = '';

    public $tooltip_description = '';

    const TEXT_DATA_TYPE = 'text';

    const DATE_DATA_TYPE = 'date';

    const DATETIME_DATA_TYPE = 'datetime';

    const NUMBER_DATA_TYPE = 'number';

    const MULTISELECT = 'multiselect';

    const BOOLEAN = 'boolean';

    public function __construct($field_name, $title)
    {
        $this->field_name = $field_name;

        $this->title = $title;
    }

    /**
     * @param string $db_field_id
     *
     * @return $this
     */
    public function set_db_field_id($db_field_id)
    {
        $this->db_field_id = $db_field_id;

        return $this;
    }

    /**
     * @param array $classes
     *
     * @return $this
     */
    public function set_classes($classes)
    {
        $this->classes = $classes;

        return $this;
    }

    public function set_options($options)
    {
        $this->options = $options;

        return $this;
    }

    public function set_required()
    {
        $this->is_required = true;

        return $this;
    }

    public function set_placeholder($placeholder)
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * @param $description
     *
     * @return $this
     */
    function set_description($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param $description
     *
     * @return $this
     */
    function set_tooltip_description($description)
    {
        $this->tooltip_description = $description;

        return $this;
    }

    public function get_data_types()
    {
        return apply_filters('fusewp_sync_data_types', [
            self::TEXT_DATA_TYPE     => esc_html__('Text', 'fusewp'),
            self::DATE_DATA_TYPE     => esc_html__('Date', 'fusewp'),
            self::DATETIME_DATA_TYPE => esc_html__('DateTime', 'fusewp'),
            self::NUMBER_DATA_TYPE   => esc_html__('Number', 'fusewp'),
            self::MULTISELECT        => esc_html__('Multiselect', 'fusewp'),
            self::BOOLEAN            => esc_html__('Boolean', 'fusewp')
        ]);
    }
}