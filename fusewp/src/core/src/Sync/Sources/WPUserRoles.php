<?php

namespace FuseWP\Core\Sync\Sources;

use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\QueueManager\QueueManager;

class WPUserRoles extends AbstractSyncSource
{
    public function __construct()
    {
        $this->title = esc_html__('User Roles', 'fusewp');

        $this->id = 'wp_user_roles';

        parent::__construct();

        // priority 9999 so user meta by other plugins must have been saved
        add_action('user_register', [$this, 'user_register_callback'], 9999);

        add_action('profile_update', [$this, 'profile_update_callback'], 10, 2);

        add_action('delete_user', [$this, 'delete_user_callback'], 20);

        add_action('add_user_role', function ($user_id, $role) {
            $this->sync_user($user_id, self::SUBSCRIBE_ACTION, '', false, $role);
        }, 99, 2);

        add_action('remove_user_role', function ($user_id, $role) {
            $this->sync_user($user_id, self::UNSUBSCRIBE_ACTION, '', false, $role);
        }, 99, 2);

        add_action('set_user_role', function ($user_id, $role, $old_roles) {

            $this->sync_user($user_id, self::SUBSCRIBE_ACTION, '', false, $role);

            if (is_array($old_roles) && ! empty($old_roles)) {
                foreach ($old_roles as $old_role) {
                    $this->sync_user($user_id, self::UNSUBSCRIBE_ACTION, '', false, $old_role);
                }
            }

        }, 99, 3);
    }

    public function user_register_callback($user_id)
    {
        remove_action('profile_update', [$this, 'profile_update_callback'], 10, 2);

        $this->sync_user($user_id, self::SUBSCRIBE_ACTION);
    }

    public function profile_update_callback($user_id, $old_user_data)
    {
        $this->sync_user($user_id, self::SUBSCRIBE_ACTION, $old_user_data->user_email);

        do_action('fusewp_profile_update', $user_id);
    }

    public function delete_user_callback($user_id)
    {
        $this->sync_user($user_id, self::UNSUBSCRIBE_ACTION);
    }

    public function unsubscribe_action_callback($user_id)
    {
        $this->sync_user($user_id, self::UNSUBSCRIBE_ACTION);
    }

    public function get_source_items()
    {
        return false;
    }

    public function get_destination_items()
    {
        $roles = get_editable_roles();

        $bucket = ['any' => esc_html__('Any Roles', 'fusewp')];
        foreach ($roles as $role_id => $role) {
            $bucket[$role_id] = $role['name'];
        }

        return $bucket;
    }

    public function get_destination_item_label()
    {
        return esc_html__('User Role', 'fusewp');
    }

    public function get_rule_information()
    {
        return '<p>' . sprintf(
                esc_html__('Sync WordPress users with your email marketing software based on their registered user roles. Changes to user profile information are automatically synced as well. And if a user is deleted, they are automatically unsubscribed. %sLearn more%s', 'fusewp'),
                '<a target="_blank" href="https://fusewp.com/article/sync-wordpress-users-email-list-based-on-user-roles/">', '</a>'
            ) . '</p>';
    }

    /**
     * @param int $user_id
     * @param string $action subscribe or unsubscribe action
     * @param string $old_email_address old user email address
     * @param bool $force_sync
     * @param string $role the user role to based subscribing/unsubscribing on
     *
     * @return void
     */
    public function sync_user($user_id, $action = self::SUBSCRIBE_ACTION, $old_email_address = '', $force_sync = false, $role = '')
    {
        if (empty($user_id)) return;

        if ($force_sync === false) {

            static $cache_bucket = [];

            $cache_key = implode(':', func_get_args());

            if (isset($cache_bucket[$cache_key])) return;

            $cache_bucket[$cache_key] = true;
        }

        $user = get_userdata($user_id);

        $user_data = $this->get_mapping_user_data($user);

        $user_roles = apply_filters('fusewp_user_sync_roles', ! empty($role) ? [$role] : $user->roles, $user_id, $action);

        $user_roles[] = 'any';

        $rule = fusewp_sync_get_rule_by_source($this->id);

        $destinations = fusewpVar($rule, 'destinations', [], true);

        if ( ! empty($destinations) && is_string($destinations)) {
            $destinations = json_decode($destinations, true);
        }

        if (is_array($destinations) && ! empty($destinations)) {

            foreach ($destinations as $destination) {

                if (empty($destination['destination_item']) || ! in_array($destination['destination_item'], $user_roles)) continue;

                $integration = fusewpVar($destination, 'integration', '', true);

                if ( ! empty($integration)) {

                    $integration = fusewp_get_registered_sync_integrations($integration);

                    $sync_action = $integration->get_sync_action();

                    if ($integration instanceof IntegrationInterface) {

                        $list_id = fusewpVar($destination, $sync_action::EMAIL_LIST_FIELD_ID, '');

                        $email_address = $user_data->get('user_email');

                        if ($action == self::UNSUBSCRIBE_ACTION) {

                            QueueManager::push([
                                'action'                => 'unsubscribe_user',
                                'source_id'             => $this->id,
                                'rule_id'               => $rule['id'],
                                'destination'           => $destination,
                                'integration'           => $sync_action->get_integration_id(),
                                'mappingUserDataEntity' => $user_data,
                                'extras'                => $user,
                                'list_id'               => $list_id,
                                'email_address'         => $email_address,
                                'old_email_address'     => $old_email_address
                            ]);

                        } else {

                            if ($destination['destination_item'] == 'any') {

                                QueueManager::push([
                                    'action'                => 'subscribe_user',
                                    'source_id'             => $this->id,
                                    'rule_id'               => $rule['id'],
                                    'destination'           => $destination,
                                    'integration'           => $sync_action->get_integration_id(),
                                    'mappingUserDataEntity' => $user_data,
                                    'extras'                => $user,
                                    'list_id'               => $list_id,
                                    'email_address'         => $email_address,
                                    'old_email_address'     => $old_email_address
                                ], 5, 1);

                                continue;
                            }

                            QueueManager::push([
                                'action'                => 'subscribe_user',
                                'source_id'             => $this->id,
                                'rule_id'               => $rule['id'],
                                'destination'           => $destination,
                                'integration'           => $sync_action->get_integration_id(),
                                'mappingUserDataEntity' => $user_data,
                                'extras'                => $user,
                                'list_id'               => $list_id,
                                'email_address'         => $email_address,
                                'old_email_address'     => $old_email_address
                            ], 5, 1);
                        }
                    }
                }
            }
        }
    }

    public function get_bulk_sync_data($source_item_id, $paged, $number)
    {
        return get_users(['fields' => 'ID', 'paged' => $paged, 'number' => $number]);
    }

    public function bulk_sync_handler($item)
    {
        $this->sync_user($item['r']);
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}