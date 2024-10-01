<?php

namespace FuseWP\Core\Admin\SettingsPage;

class ProUpgrade
{
    public function __construct()
    {
        $basename = plugin_basename(FUSEWP_SYSTEM_FILE_PATH);
        $prefix = is_network_admin() ? 'network_admin_' : '';
        add_filter("{$prefix}plugin_action_links_$basename", [$this, 'fwp_action_links'], 10, 4);
        add_filter('plugin_row_meta', array(__CLASS__, 'plugin_row_meta'), 10, 2);

        add_filter('admin_footer_text', [$this, 'admin_page_rate_us']);

    }

    /**
     * Add rating links to the admin dashboard
     *
     * @param       string $footer_text The existing footer text
     * @return      string
     */
    public function admin_page_rate_us($footer_text)
    {
        if (fusewp_is_admin_page()) {
            $rate_text = sprintf(__('Thank you for using <a href="%1$s" target="_blank">FuseWP</a>! Please <a href="%2$s" target="_blank">rate us ★★★★★</a> on <a href="%2$s" target="_blank">WordPress.org</a>', 'fusewp'),
                'https://fusewp.com',
                'https://wordpress.org/support/view/plugin-reviews/fusewp?filter=5#postform',
                'https://fusewp.com/pricing/'
            );

            return str_replace('</span>', '', $footer_text) . ' | ' . $rate_text . '</span>';
        } else {
            return $footer_text;
        }
    }

    /**
     * Show row meta on the plugin screen.
     *
     * @param    mixed $links Plugin Row Meta
     * @param    mixed $file Plugin Base file
     * @return    array
     */
    public static function plugin_row_meta($links, $file)
    {
        if (strpos($file, 'fusewp.php') !== false) {
            $row_meta = array(
                'docs' => '<a target="_blank" href="' . esc_url('https://fusewp.com/docs/') . '" aria-label="' . esc_attr__('View FuseWP documentation', 'fusewp') . '">' . esc_html__('Docs', 'fusewp') . '</a>',
                'support' => '<a target="_blank" href="' . esc_url('https://fusewp.com/support/') . '" aria-label="' . esc_attr__('Visit customer support', 'fusewp') . '">' . esc_html__('Support', 'fusewp') . '</a>',
            );

            if (!defined('FUSEWP_DETACH_LIBSODIUM')) {
                $url = 'https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=row_meta';
                $row_meta['upgrade_pro'] = '<a target="_blank" style="color:#d54e21;font-weight:bold" href="' . esc_url($url) . '" aria-label="' . esc_attr__('Upgrade to PRO', 'fusewp') . '">' . esc_html__('Go Premium', 'fusewp') . '</a>';
            }

            return array_merge($links, $row_meta);
        }

        return (array)$links;
    }

    /**
     * Action links in plugin listing page.
     */
    public function fwp_action_links($actions, $plugin_file, $plugin_data, $context)
    {
        $custom_actions = array(
            'fwp_settings' => sprintf('<a href="%s">%s</a>', FUSEWP_SETTINGS_SETTINGS_PAGE, __('Settings', 'fusewp')),
        );

        if (!defined('FUSEWP_DETACH_LIBSODIUM')) {
            $custom_actions['fwp_upgrade'] = sprintf(
                '<a style="color:#d54e21;font-weight:bold" href="%s" target="_blank">%s</a>', 'https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=action_link',
                __('Go Premium', 'fusewp')
            );
        }

        // add the links to the front of the actions list
        return array_merge($custom_actions, $actions);
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