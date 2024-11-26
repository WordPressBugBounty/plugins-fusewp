<?php

namespace FuseWP\Core\Integrations;

interface IntegrationInterface
{
    public static function features_support();

    public function has_support($flag);

    public function is_connected();

    public function connection_settings();

    /**
     * @return mixed
     */
    public function get_email_list();

    /**
     * @param $list_id
     *
     * @return ContactFieldEntity[]
     */
    public function get_contact_fields($list_id = '');

    /**
     * @return false|SyncActionInterface
     */
    public function get_sync_action();
}