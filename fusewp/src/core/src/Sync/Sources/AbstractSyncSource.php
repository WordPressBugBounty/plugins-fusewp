<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\IntegrationInterface;

abstract class AbstractSyncSource
{
    const SUBSCRIBE_ACTION = 'subscribe_action';

    const UNSUBSCRIBE_ACTION = 'unsubscribe_action';

    public $id;

    public $title;

    protected $subscribe_bucket = [];

    protected $unsubscribe_bucket = [];

    public function __construct()
    {
        add_filter('fusewp_registered_sync_sources', [$this, 'register_source']);
        add_filter('fusewp_sync_mappable_data', [$this, 'get_custom_profile_fields']);
        add_filter('fusewp_get_mapping_user_data_entity', [$this, 'get_mapping_custom_user_data'], 10, 4);

        add_action('fusewp_profile_update', function ($user_id) {

            $is_enabled = apply_filters('fusewp_enable_sync_on_profile_update', fusewp_get_settings('enable_external_profile_update_sync') == 'yes', $this->id, $user_id);

            if ($is_enabled) {

                static $cache = [];
                if ( ! isset($cache[$user_id])) {
                    $this->sync_on_update($user_id);
                    $cache[$user_id] = true;
                }
            }
        });

        add_action('shutdown', function () {
            if ( ! $this->is_shutdown_processing_disabled()) {
                $this->process_subscribe_unsubscribe_actions();
            }
        }, -999);
    }

    public function is_shutdown_processing_disabled()
    {
        return apply_filters('fusewp_disable_shutdown_processing', false, $this);
    }

    /**
     * @param $wp_user_or_id
     * @param $extras
     *
     * @return MappingUserDataEntity
     */
    public function get_mapping_user_data($wp_user_or_id, $extras = [])
    {
        $user = $wp_user_or_id;

        if ( ! is_a($wp_user_or_id, '\WP_user')) {
            $user = get_userdata($wp_user_or_id);
        }

        $loadable_data = [];

        $user_id = 0;

        if (is_a($user, '\WP_user')) {

            $loadable_data = apply_filters('fusewp_sync_user_values', [
                'user_email'      => $user->user_email,
                'first_name'      => $user->first_name,
                'last_name'       => $user->last_name,
                'description'     => $user->description,
                'user_url'        => $user->user_url,
                'user_login'      => $user->user_login,
                'display_name'    => $user->display_name,
                'nickname'        => $user->nickname,
                'user_nicename'   => $user->user_nicename,
                'user_id'         => $user->ID,
                'locale'          => $user->locale,
                'role'            => is_array($user->roles) ? reset($user->roles) : '',
                'user_registered' => $user->user_registered,
                'ip_address'      => fusewp_get_ip_address()
            ], $wp_user_or_id);

            $user_id = $user->ID;
        }

        return new MappingUserDataEntity($user_id, $loadable_data, $extras);
    }

    public function get_custom_profile_fields($fields)
    {
        return $fields;
    }

    public function get_mapping_custom_user_data($value, $field_id, $wp_user_id, $extras)
    {
        return $value;
    }

    public function sync_on_update($user_id)
    {

    }

    public function register_source($sources)
    {
        $sources[$this->id] = new static();

        return $sources;
    }

    abstract function get_source_items();

    abstract function get_destination_items();

    abstract function get_destination_item_label();

    abstract function get_rule_information();

    abstract static function get_instance();

    /**
     * User record/data to bulk sync
     *
     * @param string $source_item_id
     * @param int $paged
     * @param int $number
     *
     * @return array
     */
    abstract public function get_bulk_sync_data($source_item_id, $paged, $number);

    /**
     * @param array $item queue item
     *
     * @return void
     */
    abstract public function bulk_sync_handler($item);

    public function process_subscribe_actions($subscribe_actions)
    {
        if (is_array($subscribe_actions) && ! empty($subscribe_actions)) {

            foreach ($subscribe_actions as $item) {

                $GLOBALS['fusewp_sync_execution_rule_id'] = $item['rule_id'];
                call_user_func_array([$item['sync_action'], 'subscribe_user'], $item['payload']);
            }
        }
    }

    public function process_unsubscribe_actions($unsubscribe_actions)
    {
        if (is_array($unsubscribe_actions) && ! empty($unsubscribe_actions)) {

            foreach ($unsubscribe_actions as $item) {

                $GLOBALS['fusewp_sync_execution_rule_id'] = $item['rule_id'];
                call_user_func_array([$item['sync_action'], 'unsubscribe_user'], $item['payload']);
            }
        }
    }

    public function do_sync_execution($rule, $status, $user_id, $extras = [])
    {
        if (is_null($rule)) return;

        $email_address = '';

        // some integration eg woo guest checkout passes email address as $user_id
        if (is_numeric($user_id)) {
            $user = get_userdata($user_id);
        } else {
            $user          = get_user_by('email', $user_id);
            $email_address = $user_id;
        }

        $user_data = $this->get_mapping_user_data($user, $extras);

        $email_address = ! empty($email_address) ? $email_address : $user_data->get('user_email');

        $destinations = fusewpVar($rule, 'destinations', [], true);

        if ( ! empty($destinations) && is_string($destinations)) {
            $destinations = json_decode($destinations, true);
        }

        if (is_array($destinations) && ! empty($destinations)) {

            foreach ($destinations as $destination) {

                if (empty($destination['destination_item'])) continue;

                $integration = fusewpVar($destination, 'integration', '', true);

                if ( ! empty($integration)) {

                    $integration = fusewp_get_registered_sync_integrations($integration);

                    $sync_action = $integration->get_sync_action();

                    if ($integration instanceof IntegrationInterface) {

                        $list_id = fusewpVar($destination, $sync_action::EMAIL_LIST_FIELD_ID, '');

                        $bucket_key = md5($sync_action->get_integration_id() . $list_id);

                        $GLOBALS['fusewp_sync_source_id']             = $this->id;
                        $GLOBALS['fusewp_sync_destination'][$list_id] = $destination;

                        if ($destination['destination_item'] == 'any') {

                            $this->subscribe_bucket[] = [
                                'bucket_key'  => $bucket_key,
                                'sync_action' => $sync_action,
                                'payload'     => [
                                    $list_id,
                                    $email_address,
                                    $user_data,
                                    fusewpVar($destination, $sync_action::CUSTOM_FIELDS_FIELD_ID, []),
                                    fusewpVar($destination, $sync_action::TAGS_FIELD_ID, '')
                                ],
                                'rule_id'     => $rule['id']
                            ];

                            continue;
                        }

                        if ($destination['destination_item'] != $status) {

                            $this->unsubscribe_bucket[] = [
                                'bucket_key'  => $bucket_key,
                                'sync_action' => $sync_action,
                                'payload'     => [
                                    $list_id,
                                    $email_address
                                ],
                                'rule_id'     => $rule['id']
                            ];

                        } else {

                            $this->subscribe_bucket[] = [
                                'bucket_key'  => $bucket_key,
                                'sync_action' => $sync_action,
                                'payload'     => [
                                    $list_id,
                                    $email_address,
                                    $user_data,
                                    fusewpVar($destination, $sync_action::CUSTOM_FIELDS_FIELD_ID, []),
                                    fusewpVar($destination, $sync_action::TAGS_FIELD_ID, '')
                                ],
                                'rule_id'     => $rule['id']
                            ];
                        }
                    }
                }
            }
        }

        if ($this->is_shutdown_processing_disabled()) {
            $this->process_subscribe_unsubscribe_actions();
        }
    }


    public function process_subscribe_unsubscribe_actions()
    {
        if (empty($this->subscribe_bucket) && empty($this->unsubscribe_bucket)) return;

        $subscribe_bucket_keys = wp_list_pluck($this->subscribe_bucket, 'bucket_key');

        // any unsubscribe action from a list that is in subscribe action bucket (which will resubscribe them again) is removed
        // to avoid unsubscription (by unsubscribe actions bucket) and re-subscription (by subscribe actions bucket)
        $filtered_unsubscribe_bucket = array_reduce($this->unsubscribe_bucket, function ($carry, $item) use ($subscribe_bucket_keys) {
            if ( ! in_array($item['bucket_key'], $subscribe_bucket_keys)) {
                $carry[] = $item;
            }

            return $carry;
        }, []);

        $this->process_unsubscribe_actions($filtered_unsubscribe_bucket);
        $this->process_subscribe_actions($this->subscribe_bucket);

        // empty after processing
        $this->subscribe_bucket   = [];
        $this->unsubscribe_bucket = [];
    }

    public function get_source_data()
    {
        $source      = fusewpVarPOST('source', $GLOBALS['fusewp_sync_rule_source_id'] ?? '', true);
        $source_item = fusewpVarPOST('source_item', $GLOBALS['fusewp_sync_rule_source_item_id'] ?? '', true);

        return [$source, $source_item];
    }
}