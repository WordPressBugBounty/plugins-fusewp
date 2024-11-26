<?php

namespace FuseWP\Core\Admin;

use FuseWPVendor\PAnD as PAnD;

class AdminNotices
{
    public function __construct()
    {
        add_action('admin_init', function () {
            if (fusewp_is_admin_page()) {
                remove_all_actions('admin_notices');
            }

            add_action('admin_notices', [$this, 'admin_notices_bucket']);

            add_filter('removable_query_args', [$this, 'removable_query_args']);
        });

        add_action('admin_init', ['\FuseWPVendor\PAnD', 'init']);
        add_action('admin_init', [$this, 'dismiss_leave_review_notice_forever']);
    }

    public function admin_notices_bucket()
    {
        do_action('fusewp_admin_notices');

        $this->review_plugin_notice();

        $this->integrations_upsell();
    }

    public function is_admin_notice_show()
    {
        return apply_filters('fusewp_ads_admin_notices_display', true);
    }

    public function removable_query_args($args)
    {
        $args[] = 'settings-updated';
        $args[] = 'license-settings-updated';
        $args[] = 'oauth-error';
        $args[] = 'oauth-provider';
        $args[] = 'license';

        return $args;
    }

    public function dismiss_leave_review_notice_forever()
    {
        if ( ! empty($_GET['fusewp_admin_action']) && $_GET['fusewp_admin_action'] == 'dismiss_leave_review_forever') {
            update_option('fusewp_dismiss_leave_review_forever', true);

            wp_safe_redirect(esc_url_raw(remove_query_arg('fusewp_admin_action')));
            exit;
        }
    }

    /**
     * Display one-time admin notice to review plugin at least 7 days after installation
     */
    public function review_plugin_notice()
    {
        if ( ! current_user_can('manage_options')) return;

        if ( ! PAnD::is_admin_notice_active('fusewp-review-plugin-notice-forever')) return;

        if (get_option('fusewp_dismiss_leave_review_forever', false)) return;

        $install_date = get_option('fusewp_install_date', '');

        if (empty($install_date)) return;

        $diff = round((time() - strtotime($install_date)) / 24 / 60 / 60);

        if ($diff < 7) return;

        $review_url = 'https://wordpress.org/support/plugin/fusewp/reviews/?filter=5#new-post';

        $dismiss_url = esc_url_raw(add_query_arg('fusewp_admin_action', 'dismiss_leave_review_forever'));

        $notice = sprintf(
            __('Hey, I noticed you have been using FuseWP for at least 7 days now - that\'s awesome! Could you please do me a BIG favor and give it a %1$s5-star rating on WordPress?%2$s This will help us spread the word and boost our motivation - thanks!', 'fusewp'),
            '<a href="' . $review_url . '" target="_blank">',
            '</a>'
        );
        $label  = __('Sure! I\'d love to give a review', 'fusewp');

        $dismiss_label = __('Dismiss Forever', 'fusewp');

        $notice .= "<div style=\"margin:10px 0 0;\"><a href=\"$review_url\" target='_blank' class=\"button-primary\">$label</a></div>";
        $notice .= "<div style=\"margin:10px 0 0;\"><a href=\"$dismiss_url\">$dismiss_label</a></div>";

        echo '<div data-dismissible="fusewp-review-plugin-notice-forever" class="update-nag notice notice-warning is-dismissible">';
        echo "<p>$notice</p>";
        echo '</div>';
    }

    public function integrations_upsell()
    {
        if ( ! $this->is_admin_notice_show()) return;

        $upsells = [
            [
                'id'        => 'memberpress',
                'is_active' => class_exists('\MeprAppCtrl'),
                'url'       => 'https://fusewp.com/article/sync-memberpress-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=memberpress_admin_notice',
                'message'   => esc_html__('Did you know you can sync your MemberPress members to your CRM and email list based on their memberships and subscription status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'woocommerce',
                'is_active' => class_exists('\WooCommerce'),
                'url'       => 'https://fusewp.com/article/sync-woocommerce-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=woocommerce_admin_notice',
                'message'   => esc_html__('Did you know you can sync customers after WooCommerce checkout to your CRM and email list based on their purchased product and order status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'woocommerce_memberships',
                'is_active' => class_exists('\WC_Memberships'),
                'url'       => 'https://fusewp.com/article/sync-woocommerce-memberships-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=woocommerce_memberships_admin_notice',
                'message'   => esc_html__('Did you know you can sync your members in WooCommerce Memberships to your CRM and email list based on their subscribed plan and membership status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'woocommerce_subscriptions',
                'is_active' => class_exists('\WC_Subscriptions'),
                'url'       => 'https://fusewp.com/article/sync-woocommerce-subscriptions-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=woocommerce_subscriptions_admin_notice',
                'message'   => esc_html__('Did you know you can sync your customers in WooCommerce Subscriptions to your CRM and email list based on their subscribed product and subscription status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'learndash',
                'is_active' => class_exists('\SFWD_LMS'),
                'url'       => 'https://fusewp.com/article/sync-learndash-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=learndash_admin_notice',
                'message'   => esc_html__('Did you know you can sync your LearnDash students or users to your CRM and email list based on their enrolled courses and groups, and enrollment status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'lifterlms',
                'is_active' => class_exists('\LifterLMS'),
                'url'       => 'https://fusewp.com/article/sync-lifterlms-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=lifterlms_admin_notice',
                'message'   => esc_html__('Did you know you can sync your LifterLMS students or users to your CRM and email list based on their enrolled courses and memberships, and enrollment status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'tutor_lms',
                'is_active' => function_exists('tutor_lms'),
                'url'       => 'https://fusewp.com/article/sync-tutor-lms-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=tutor_lms_admin_notice',
                'message'   => esc_html__('Did you know you can sync your Tutor LMS students or users to your CRM and email list based on their enrolled courses and enrollment status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'paid_memberships_pro',
                'is_active' => defined('PMPRO_VERSION'),
                'url'       => 'https://fusewp.com/article/sync-paid-memberships-pro-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=paid_memberships_pro_admin_notice',
                'message'   => esc_html__('Did you know you can sync your Paid Memberships Pro users to your CRM and email list based on their subscribed membership level and membership status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'profilepress',
                'is_active' => defined('PPRESS_VERSION_NUMBER'),
                'url'       => 'https://fusewp.com/article/sync-profilepress-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=profilepress_admin_notice',
                'message'   => esc_html__('Did you know you can sync your ProfilePress users to your CRM and email list based on their subscribed membership plan and subscription status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'restrict_content_pro',
                'is_active' => class_exists('\Restrict_Content_Pro'),
                'url'       => 'https://fusewp.com/article/sync-restrict-content-pro-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=learndash_admin_notice',
                'message'   => esc_html__('Did you know you can sync your Restrict Content Pro users to your CRM and email list based on their subscribed membership level and membership status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'ultimate_member',
                'is_active' => class_exists('\UM'),
                'url'       => 'https://fusewp.com/article/connect-ultimate-member-email-marketing-software/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=ultimate_member_admin_notice',
                'message'   => esc_html__('Did you know you can sync registered users via Ultimate Member registration to your CRM and email list? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'easy_digital_downloads',
                'is_active' => class_exists('\Easy_Digital_Downloads'),
                'url'       => 'https://fusewp.com/article/sync-easy-digital-downloads-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=easy_digital_downloads_admin_notice',
                'message'   => esc_html__('Did you know you can sync customers in Easy Digital Downloads to your CRM and email list based on their purchased products, order and subscription status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'gravity_forms',
                'is_active' => class_exists('\GFForms'),
                'url'       => 'https://fusewp.com/article/sync-gravity-forms-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=gravity_forms_admin_notice',
                'message'   => esc_html__('Did you know you can sync Gravity Forms to your CRM and email list after payment and submission based on the form submitted, order and subscription status? %sLearn more%s', 'fusewp')
            ],
            [
                'id'        => 'wpforms',
                'is_active' => function_exists('wpforms'),
                'url'       => 'https://fusewp.com/article/sync-wpforms-email-marketing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=gravity_forms_admin_notice',
                'message'   => esc_html__('Did you know you can sync WPForms to your CRM and email list after form submission and user registration based on the form submitted? %sLearn more%s', 'fusewp')
            ]
        ];

        foreach ($upsells as $upsell) {

            $notice_id = sprintf('fusewp_show_%s_features-forever', $upsell['id']);

            if ( ! PAnD::is_admin_notice_active($notice_id)) {
                continue;
            }

            if ( ! $upsell['is_active']) continue;

            $notice = sprintf($upsell['message'], '<a href="' . esc_url($upsell['url']) . '" target="_blank">', '</a>');
            echo '<div data-dismissible="' . esc_attr($notice_id) . '" class="notice notice-info is-dismissible">';
            echo "<p>$notice</p>";
            echo '</div>';
        }
    }

    /**
     * @return self
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}