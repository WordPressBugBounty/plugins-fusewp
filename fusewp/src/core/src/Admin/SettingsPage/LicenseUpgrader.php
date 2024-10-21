<?php

namespace FuseWP\Core\Admin\SettingsPage;

use FuseWP\CustomSettingsPageApi;

class LicenseUpgrader
{
    public function __construct()
    {
        add_action('plugins_loaded', function () {

            if ( ! class_exists('\FuseWP\Libsodium\Libsodium', false)) {

                add_filter('fusewp_settings_header_menu_tabs', [$this, 'add_menu']);

                add_action('fusewp_admin_settings_page_license', [$this, 'admin_page']);

                add_action('admin_enqueue_scripts', [$this, 'settings_enqueues']);

                add_action('wp_ajax_fusewp_connect_url', array($this, 'generate_url'));
            }

            add_action('wp_ajax_nopriv_fusewp_connect_process', [$this, 'process']);
        });
    }

    public function add_menu($tabs)
    {
        // changed from 11 to 99 to move license tab after General
        $tabs[99] = ['id' => 'license', 'url' => add_query_arg(['view' => 'license'], FUSEWP_SETTINGS_SETTINGS_PAGE), 'label' => esc_html__('License', 'fusewp')];

        return $tabs;
    }

    public function admin_page()
    {
        add_action('wp_cspa_main_content_area', array($this, 'admin_page_callback'), 10, 2);

        $instance = CustomSettingsPageApi::instance([], 'fusewp_license', esc_html__('License', 'fusewp'));
        $instance->remove_white_design();
        $instance->remove_h2_header();
        $instance->build(true);
    }

    public function admin_page_callback()
    {
        $nonce = wp_create_nonce('fusewp-connect-url');

        ob_start();

        ?>
        <style>
            .fusewp-admin-wrap .wrap h2 {
                display: none;
            }

            .fusewp-admin .remove_white_styling #post-body-content .form-table th {
                width: 200px !important;
            }

            .fusewp-admin .remove_white_styling #post-body-content input[type=text] {
                width: 25em !important;
            }
        </style>

        <div class="fusewp-lite-license-wrap">
            <p style="font-size: 110%;">
                <?php esc_html_e("You're using FuseWP Lite - no license needed. Enjoy! 😊", 'fusewp'); ?>
            </p>

            <p class="description" style="margin-bottom: 8px;">
                <?php
                echo wp_kses_post(
                    sprintf(
                    /* translators: %1$s Opening anchor tag, do not translate. %2$s Closing anchor tag, do not translate. */
                        __(
                            'Already purchased? Simply %1$sretrieve your license key%2$s and enter it below to connect with FuseWP Pro.',
                            'fusewp'
                        ),
                        sprintf(
                            '<a href="%s" target="_blank" rel="noopener noreferrer">',
                            'https://fusewp.com/account/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page'
                        ),
                        '</a>'
                    )
                );
                ?>
            </p>

            <div class="fusewp-license-field">
                <input
                    type="text"
                    id="fusewp-connect-license-key"
                    name="fusewp-license-key"
                    value=""
                    class="regular-text"
                    style="line-height: 1; font-size: 1.15rem; padding: 10px;"
                />

                <button
                    class="button button-secondary fusewp-license-button"
                    id="fusewp-connect-license-submit"
                    data-connecting="<?php esc_attr_e('Connecting...', 'fusewp'); ?>"
                    data-connect="<?php esc_attr_e('Unlock Premium Features', 'fusewp'); ?>"
                >
                    <?php esc_html_e('Unlock Premium Features', 'fusewp'); ?>
                </button>

                <input type="hidden" name="fusewp-action" value="fusewp-connect"/>
                <input type="hidden" id="fusewp-connect-license-nonce" name="fusewp-connect-license-nonce" value="<?php echo esc_attr($nonce); ?>"/>
            </div>

            <div id="fusewp-connect-license-feedback" class="fusewp-license-message"></div>

            <div class="fusewp-settings-upgrade">
                <div class="fusewp-settings-upgrade__inner">
                    <span class="dashicons dashicons-unlock" style="font-size: 40px; width: 40px; height: 50px;"></span>
                    <h3>
                        <?php esc_html_e('Unlock Powerful Premium Features', 'fusewp'); ?>
                    </h3>

                    <ul>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Double Optin Control', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Custom Field Mapping', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Assign Tags to Users', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-woocommerce-subscriptions-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('WooCommerce Subscriptions Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-woocommerce-memberships-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('WooCommerce Memberships Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-easy-digital-downloads-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Easy Digital Downloads Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-profilepress-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('ProfilePress Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-memberpress-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('MemberPress Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-paid-memberships-pro-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Paid Memberships Pro Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-learndash-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('LearnDash Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-lifterlms-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('LifterLMS Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-tutor-lms-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Tutor LMS Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-restrict-content-pro-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Restrict Content Pro Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/advanced-custom-fields/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Advanced Custom Fields Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-gravity-forms-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Advanced Gravity Forms Sync', 'fusewp'); ?>
                            </a>
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            <a href="https://fusewp.com/article/sync-wpforms-email-marketing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Advanced WPForms Sync', 'fusewp'); ?>
                            </a>
                        </li>
                    </ul>

                    <a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" class="button button-primary button-large fusewp-upgrade-btn fusewp-upgrade-btn-large" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Upgrade to FuseWP Premium', 'fusewp'); ?>
                    </a>
                </div>

                <div class="fusewp-upgrade-btn-subtext">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" role="img" aria-hidden="true" focusable="false">
                        <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
                    </svg>

                    <?php
                    echo wp_kses(
                        sprintf(
                        /* translators: %1$s Opening anchor tag, do not translate. %2$s Closing anchor tag, do not translate. */
                            __(
                                '<strong>Bonus</strong>: Loyal FuseWP Lite users get <u>10%% off</u> regular price using the coupon code <u>10PERCENTOFF</u>, automatically applied at checkout. %1$sUpgrade to Premium →%2$s',
                                'fusewp'
                            ),
                            sprintf(
                                '<a href="%s" rel="noopener noreferrer" target="_blank">',
                                'https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page'
                            ),
                            '</a>'
                        ),
                        array(
                            'a'      => array(
                                'href'   => true,
                                'rel'    => true,
                                'target' => true,
                            ),
                            'strong' => array(),
                            'u'      => array(),
                        )
                    );
                    ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function settings_enqueues()
    {
        wp_enqueue_script(
            'fusewp-license-connect',
            FUSEWP_ASSETS_URL . "js/license.js",
            ['jquery', 'wp-util'],
            FUSEWP_VERSION_NUMBER,
            true
        );
    }

    public function generate_url()
    {
        check_ajax_referer('fusewp-connect-url', 'nonce');

        // Check for permissions.
        if ( ! current_user_can('install_plugins')) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to install plugins.', 'fusewp')]);
        }

        $key = ! empty($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';

        if (empty($key)) {
            wp_send_json_error(['message' => esc_html__('Please enter your license key to connect.', 'fusewp')]);
        }

        if (class_exists('\FuseWP\Libsodium\Libsodium', false)) {
            wp_send_json_error(['message' => esc_html__('Only the Lite version can be upgraded.', 'fusewp')]);
        }

        $oth = hash('sha512', wp_rand());

        update_option('fusewp_connect_token', $oth);
        update_option('fusewp_license_key', $key);

        $version  = FUSEWP_VERSION_NUMBER;
        $endpoint = admin_url('admin-ajax.php');
        $redirect = FUSEWP_SETTINGS_SETTINGS_PAGE;
        $url      = add_query_arg(
            [
                'key'      => $key,
                'oth'      => $oth,
                'endpoint' => $endpoint,
                'version'  => $version,
                'siteurl'  => \admin_url(),
                'homeurl'  => \home_url(),
                'redirect' => rawurldecode(base64_encode($redirect)), // phpcs:ignore
                'v'        => 1,
            ],
            'https://upgrade.fusewp.com'
        );

        wp_send_json_success(['url' => $url]);
    }

    public function process()
    {
        $error = wp_kses(
            sprintf(
            /* translators: %1$s Opening anchor tag, do not translate. %2$s Closing anchor tag, do not translate. */
                __(
                    'Oops! We could not automatically install an upgrade. Please download the plugin from fusewp.com and install it manually.',
                    'fusewp'
                )
            ),
            [
                'a' => [
                    'target' => true,
                    'href'   => true,
                ],
            ]
        );

        $post_oth = ! empty($_REQUEST['oth']) ? sanitize_text_field($_REQUEST['oth']) : '';
        $post_url = ! empty($_REQUEST['file']) ? esc_url_raw($_REQUEST['file']) : '';

        if (empty($post_oth) || empty($post_url)) {
            wp_send_json_error(['message' => $error, 'code_err' => '1']);
        }

        $oth = get_option('fusewp_connect_token');

        if (empty($oth)) {
            wp_send_json_error(['message' => $error, 'code_err' => '2']);
        }

        if ( ! hash_equals($oth, $post_oth)) {
            wp_send_json_error(['message' => $error, 'code_err' => '3']);
        }

        delete_option('fusewp_connect_token');

        // Set the current screen to avoid undefined notices.
        set_current_screen('toplevel_page_fusewp-settings');

        $url = FUSEWP_SETTINGS_SETTINGS_PAGE;

        // Verify pro not activated.
        if (class_exists('\FuseWP\Libsodium\Libsodium', false)) {
            wp_send_json_success(esc_html__('Plugin installed & activated.', 'fusewp'));
        }

        $creds = request_filesystem_credentials($url, '', false, false, null);

        // Check for file system permissions.
        if (false === $creds || ! \WP_Filesystem($creds)) {
            wp_send_json_error(['message' => $error, 'code_err' => '4']);
        }

        /*
         * We do not need any extra credentials if we have gotten this far, so let's install the plugin.
         */

        // Do not allow WordPress to search/download translations, as this will break JS output.
        remove_action('upgrader_process_complete', ['Language_Pack_Upgrader', 'async_upgrade'], 20);

        $upgrader = ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if ( ! file_exists($upgrader)) {
            wp_send_json_error(
                array(
                    'message' => $error,
                )
            );
        }

        require_once $upgrader;

        // Create the plugin upgrader with our custom skin.
        $installer = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());

        // Error check.
        if ( ! method_exists($installer, 'install')) {
            wp_send_json_error(['message' => $error, 'code_err' => '5']);
        }

        $license = get_option('fusewp_license_key', '');

        if (empty($license)) {
            wp_send_json_error([
                'message'  => esc_html__('You are not licensed.', 'fusewp'),
                'code_err' => '6'
            ]);
        }

        $installer->install($post_url, ['overwrite_package' => true]);

        // Flush the cache and return the newly installed plugin basename.
        wp_cache_flush();

        $plugin_basename = $installer->plugin_info();

        if ($plugin_basename) {

            update_option('fusewp_upgrader_success_flag', 'true');

            // Activate the plugin silently.
            $activated = activate_plugin($plugin_basename, '', false, true);

            if ( ! is_wp_error($activated)) {
                wp_send_json_success(esc_html__('Plugin installed & activated.', 'fusewp'));
            }
        }

        wp_send_json_error(['message' => $error, 'code_err' => '7']);
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
