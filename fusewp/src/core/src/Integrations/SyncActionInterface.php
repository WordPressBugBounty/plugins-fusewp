<?php

namespace FuseWP\Core\Integrations;

use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

interface SyncActionInterface
{
    public function get_integration_id();

    public function get_fields($index);

    public function get_list_fields($list_id, $index = 0);

    public function get_list_fields_default_data();

    /**
     * @param string $list_id
     * @param string $email_address
     * @param MappingUserDataEntity $mappingUserDataEntity
     * @param array $custom_fields
     * @param array|string $tags
     * @param string $old_email_address
     *
     * @return bool
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '');

    /**
     * @param string $list_id
     * @param string $email_address
     *
     * @return bool
     */
    public function unsubscribe_user($list_id, $email_address);
}