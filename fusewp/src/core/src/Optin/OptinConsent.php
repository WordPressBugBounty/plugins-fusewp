<?php

namespace FuseWP\Core\Optin;

use FuseWP\Core\Core;

/**
 * Where opt-in consent is kept: one row per email address, holding when, where
 * and from which IP the contact ticked the box. Guests and users alike, so a
 * guest checkout is covered too.
 */
class OptinConsent
{
    protected static $cache = [];

    public function __construct()
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
    }

    public static function table()
    {
        return Core::optin_consent_db_table();
    }

    public static function create_table_sql($collate)
    {
        $table = self::table();

        return "CREATE TABLE IF NOT EXISTS $table (
                  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                  email varchar(190) NOT NULL,
                  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                  source varchar(100) NOT NULL DEFAULT '',
                  ip_address varchar(45) NOT NULL DEFAULT '',
                  consented_at datetime NOT NULL,
                  PRIMARY KEY (id),
                  UNIQUE KEY email (email)
                ) $collate;";
    }

    protected static function normalize($email)
    {
        return strtolower(trim((string)$email));
    }

    /**
     * Keeps the first consent of an address; a later tick does not overwrite it, but
     * fills in the user ID if the first consent was given before the account existed.
     *
     * @param string $email
     * @param string $source the checkpoint or sync source the box was ticked on
     * @param int $user_id
     *
     * @return bool
     */
    public static function record($email, $source = '', $user_id = 0)
    {
        global $wpdb;

        $email = self::normalize($email);

        if ( ! is_email($email)) return false;

        if (self::has($email)) {

            if ($user_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE " . self::table() . " SET user_id = %d WHERE email = %s AND user_id = 0",
                    absint($user_id), $email
                ));
            }

            return true;
        }

        // cron and queue workers run outside the visitor's request; their IP is not the contact's.
        $ip = wp_doing_cron() ? '' : (string)fusewp_get_ip_address();

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO " . self::table() . " (email, user_id, source, ip_address, consented_at) VALUES (%s, %d, %s, %s, %s)",
                $email, absint($user_id), sanitize_key($source), $ip, current_time('mysql', true)
            )
        );

        if (false === $inserted) return false;

        self::$cache[$email] = true;

        do_action('fusewp_optin_consent_recorded', $email, $source, $user_id);

        return true;
    }

    /**
     * @param string $email
     * @param int $user_id also checks the user's current address, in case it changed since
     *
     * @return bool
     */
    public static function has($email, $user_id = 0)
    {
        global $wpdb;

        $emails = array_filter([self::normalize($email)]);

        if ($user_id && ($user = get_userdata($user_id))) {
            $emails[] = self::normalize($user->user_email);
        }

        foreach (array_unique($emails) as $address) {

            if ( ! isset(self::$cache[$address])) {
                self::$cache[$address] = (bool)$wpdb->get_var(
                    $wpdb->prepare("SELECT id FROM " . self::table() . " WHERE email = %s", $address)
                );
            }

            if (self::$cache[$address]) return true;
        }

        // consent given under another address by the same account, eg. a billing email
        // that differs from the account email, or an account email changed since.
        if ($user_id) {
            return (bool)$wpdb->get_var(
                $wpdb->prepare("SELECT id FROM " . self::table() . " WHERE user_id = %d LIMIT 1", absint($user_id))
            );
        }

        return false;
    }

    /**
     * Consent rows of an address, plus those of the account that owns it.
     *
     * @return array[]
     */
    public static function get_all($email)
    {
        global $wpdb;

        $email = self::normalize($email);
        $user  = get_user_by('email', $email);

        return (array)$wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE email = %s OR (user_id > 0 AND user_id = %d)",
                $email, $user ? $user->ID : 0
            ),
            ARRAY_A
        );
    }

    public static function get($email)
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table() . " WHERE email = %s", self::normalize($email)),
            ARRAY_A
        );
    }

    /**
     * Removes every consent row of an address and of the account that owns it.
     *
     * @return int rows removed
     */
    public static function withdraw($email)
    {
        global $wpdb;

        $email   = self::normalize($email);
        $removed = 0;

        foreach (self::get_all($email) as $row) {
            unset(self::$cache[$row['email']]);
            $removed += (int)$wpdb->delete(self::table(), ['id' => $row['id']], ['%d']);
        }

        do_action('fusewp_optin_consent_withdrawn', $email);

        return $removed;
    }

    public function register_exporter($exporters)
    {
        $exporters['fusewp-optin-consent'] = [
            'exporter_friendly_name' => esc_html__('FuseWP Opt-in Consent', 'fusewp'),
            'callback'               => function ($email) {

                $data = [];

                foreach (self::get_all($email) as $row) {
                    $data[] = [
                        'group_id'    => 'fusewp-optin-consent',
                        'group_label' => esc_html__('Newsletter Opt-in Consent', 'fusewp'),
                        'item_id'     => 'fusewp-optin-consent-' . $row['id'],
                        'data'        => [
                            ['name' => esc_html__('Email', 'fusewp'), 'value' => $row['email']],
                            ['name' => esc_html__('Consented on', 'fusewp'), 'value' => $row['consented_at'] . ' UTC'],
                            ['name' => esc_html__('Where', 'fusewp'), 'value' => $row['source']],
                            ['name' => esc_html__('IP address', 'fusewp'), 'value' => $row['ip_address']],
                        ]
                    ];
                }

                return ['data' => $data, 'done' => true];
            }
        ];

        return $exporters;
    }

    public function register_eraser($erasers)
    {
        $erasers['fusewp-optin-consent'] = [
            'eraser_friendly_name' => esc_html__('FuseWP Opt-in Consent', 'fusewp'),
            'callback'             => function ($email) {

                $removed = self::withdraw($email);

                return ['items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
            }
        ];

        return $erasers;
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
