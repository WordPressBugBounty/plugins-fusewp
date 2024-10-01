<?php

namespace FuseWP\Core\Sync\Sources;

class MappingUserDataEntity
{
    protected $user_id;

    protected $user_data = [];

    protected $extras = [];

    public function __construct($user_id, $loaded_user_data = [], $extras = [])
    {
        $this->user_id   = $user_id;
        $this->user_data = $loaded_user_data;
        $this->extras    = $extras;
    }

    public function get_all()
    {
        return $this->user_data;
    }

    public function get($field_id, $default = '')
    {
        return apply_filters(
            'fusewp_get_mapping_user_data_entity',
            fusewpVar($this->user_data, $field_id, $default),
            $field_id,
            $this->user_id,
            $this->extras
        );
    }
}