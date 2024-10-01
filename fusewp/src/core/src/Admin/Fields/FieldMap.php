<?php

namespace FuseWP\Core\Admin\Fields;

use FuseWP\Core\Integrations\ContactFieldEntity;

class FieldMap extends AbstractFieldEntity
{
    public $integration_name;

    public $mappable_data;

    /** @var ContactFieldEntity[] */
    public $integration_contact_fields;

    private $db_data = [];

    public function set_integration_name($integration_name)
    {
        $this->integration_name = $integration_name;

        return $this;
    }

    public function set_integration_contact_fields($integration_contact_fields)
    {
        $this->integration_contact_fields = apply_filters(
            'fusewp_fieldmap_integration_contact_fields',
            $integration_contact_fields
        );

        return $this;
    }

    public function set_mappable_data($mappable_data)
    {
        $this->mappable_data = $mappable_data;

        return $this;
    }

    public function render($db_value = '')
    {
        $this->db_data = empty($db_value) ? [] : $db_value;
        ?>
        <table cellspacing="0" class="widefat <?php echo esc_attr(implode(' ', $this->classes)) ?>">
            <thead>
            <tr>
                <th class="fusewp-map-field-table-data"><?php esc_html_e('Data', 'fusewp'); ?></th>
                <th class="fusewp-map-field-table-data-type"><?php esc_html_e('Data Type', 'fusewp'); ?></th>
                <th class="fusewp-map-field-table-field-value"><?php printf('%s Field', esc_html($this->integration_name)) ?></th>
                <th class="fusewp-map-field-table-actions"></th>
            </tr>
            </thead>
            <tbody>
            <?php if ( ! is_array($this->db_data) || empty($this->db_data) || ! isset($this->db_data['mappable_data'])) :
                $this->field_map_row();
            else:
                foreach ($this->db_data['mappable_data'] as $index => $mappable_datum) :
                    $this->field_map_row($index);
                endforeach;
            endif;
            ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="4">
                    <a href="#" class="button button-secondary fusewp_map_field_btn"><?php esc_html_e('Map Another Field', 'fusewp'); ?></a>
                </td>
            </tr>
            </tfoot>
        </table>
        <?php
    }

    protected function field_map_row($index = 0)
    {
        $mappable_data       = fusewpVar($this->db_data, 'mappable_data', []);
        $mappable_data_types = fusewpVar($this->db_data, 'mappable_data_types', []);
        $field_values        = fusewpVar($this->db_data, 'field_values', []);
        ?>
        <tr>
            <td class="fusewp-map-field-table-data">
                <select name="<?php echo esc_attr($this->field_name); ?>[mappable_data][]">
                    <option value="">&mdash;&mdash;&mdash;</option>
                    <?php if (is_array($this->mappable_data)) : ?>
                        <?php foreach ($this->mappable_data as $opt_name => $opt_value): ?>
                            <optgroup label="<?php echo esc_attr($opt_name) ?>">
                                <?php foreach ($opt_value as $opt_sub_name => $opt_sub_value): ?>
                                    <option value="<?php echo esc_attr($opt_sub_name); ?>" <?php selected($mappable_data[$index], $opt_sub_name); ?>><?php echo esc_html($opt_sub_value); ?></option>
                                <?php endforeach ?>
                            </optgroup>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </td>
            <td class="fusewp-map-field-table-data-type">
                <select name="<?php echo esc_attr($this->field_name); ?>[mappable_data_types][]">
                    <?php foreach ($this->get_data_types() as $dt_name => $dt_value): ?>
                        <option value="<?php echo esc_attr($dt_name); ?>" <?php selected($mappable_data_types[$index], $dt_name); ?>><?php echo esc_html($dt_value); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="fusewp-map-field-table-field-value">
                <select name="<?php echo esc_attr($this->field_name); ?>[field_values][]">
                    <option value="">&mdash;&mdash;&mdash;</option>
                    <?php foreach ($this->integration_contact_fields as $contact_field):
                        $option_label = sprintf(
                            '%s%s%s',
                            $contact_field->name,
                            $contact_field->is_required ? '*' : '',
                            ! empty($contact_field->data_type) ? ' (' . $contact_field->get_field_type_label() . ')' : ''
                        );
                        ?>
                        <option value="<?php echo esc_attr($contact_field->id); ?>" <?php selected($field_values[$index], $contact_field->id); ?>>
                            <?php echo esc_html($option_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="fusewp-map-field-table-actions">
                <span class="fusewp-map-field-table-delete-icon"><span class="dashicons dashicons-no-alt"></span></span>
            </td>
        </tr>
        <?php
    }
}