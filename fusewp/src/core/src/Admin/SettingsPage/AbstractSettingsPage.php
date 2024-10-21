<?php

namespace FuseWP\Core\Admin\SettingsPage;

// Exit if accessed directly

if ( ! defined('ABSPATH')) {
    exit;
}

abstract class AbstractSettingsPage
{
    protected $option_name;

    public static $parent_menu_url_map = [];

    private function getMenuIcon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="150" height="150" viewBox="0 0 11.71 11.71" shape-rendering="geometricPrecision" image-rendering="optimizeQuality" fill-rule="nonzero" fill="#a6aaad" xmlns:v="https://vecta.io/nano"><path d="M5.85 3.39c1.49 0 2.86.42 3.86 1.09 1.1.73 1.79 1.78 1.79 2.97s-.69 2.24-1.79 2.98c-1 .67-2.37 1.08-3.86 1.08s-2.86-.41-3.86-1.08C.89 9.69.21 8.64.21 7.45s.68-2.24 1.78-2.97c1-.67 2.37-1.09 3.86-1.09zM8.76 5.9c-.73-.49-1.76-.79-2.91-.79s-2.17.3-2.9.79c-.63.42-1.02.97-1.02 1.55S2.32 8.58 2.95 9c.73.49 1.75.79 2.9.79s2.18-.3 2.91-.79c.63-.42 1.02-.97 1.02-1.55S9.39 6.32 8.76 5.9z"/><path d="M1.95 7.2C1.4 6.82.96 6.37.65 5.85c.31-.51.75-.97 1.3-1.35.1.49.46.94 1 1.3.02.02.05.04.07.05-.02.02-.05.04-.07.05-.54.36-.9.81-1 1.3z"/><path d="M5.85.19c1.49 0 2.86.42 3.86 1.09 1.1.74 1.79 1.79 1.79 2.97 0 1.19-.69 2.24-1.79 2.98-1 .67-2.37 1.08-3.86 1.08S2.99 7.9 1.99 7.23C.89 6.49.21 5.44.21 4.25c0-1.18.68-2.23 1.78-2.97C2.99.61 4.36.19 5.85.19zM8.76 2.7c-.73-.48-1.76-.78-2.91-.78s-2.17.3-2.9.78c-.63.42-1.02.97-1.02 1.55s.39 1.13 1.02 1.55c.73.49 1.75.79 2.9.79s2.18-.3 2.91-.79c.63-.42 1.02-.97 1.02-1.55S9.39 3.12 8.76 2.7z"/></svg>');
    }

    public function register_core_menu()
    {
        add_menu_page(
            __('FuseWP Plugin', 'fusewp'),
            __('FuseWP', 'fusewp'),
            'manage_options',
            FUSEWP_SETTINGS_SETTINGS_SLUG,
            '',
            $this->getMenuIcon()
        );

        do_action('fusewp_register_menu_page_' . $this->active_menu_tab() . '_' . $this->active_submenu_tab());

        do_action('fusewp_register_menu_page');

        add_filter('admin_body_class', array($this, 'add_admin_body_class'));
    }

    /** --------------------------------------------------------------- */

    // commented out to prevent any fatal error
    //abstract function default_header_menu();

    public function header_menu_tabs()
    {
        return [];
    }

    public function header_submenu_tabs()
    {
        return [];
    }

    public function settings_page_header($active_menu = '', $active_submenu = '')
    {
        $submenus_count = count($this->header_menu_tabs());
        ?>

		<div class="fusewp-admin-wrap">
			<div class="fwp-admin-banner<?php echo defined('FUSEWP_DETACH_LIBSODIUM') ? ' fusewp-pro' : ' fusewp-not-pro' ?><?php echo $submenus_count < 2 ? ' fusewp-no-submenu' : '' ?>">
                <?php $this->settings_page_header_menus($active_menu); ?>
			</div>
            <?php

            $submenus = $this->header_submenu_tabs();

            if ( ! empty($submenus) && count($submenus) > 1) {
                $this->settings_page_header_sub_menus($active_menu, $active_submenu);
            }
            ?>
		</div>
        <?php
        do_action('fusewp_settings_page_header', $active_menu, $active_submenu);
    }

    public function settings_page_header_menus($active_menu)
    {
        $menus    = $this->header_menu_tabs();
        $logo_url = FUSEWP_ASSETS_URL . 'images/fusewp-logo.svg';
        ?>

		<div class="fusewp-header-menus">

			<div class="fwp-admin-banner__logo">
				<img src="<?php echo $logo_url ?>" alt="" style="max-width: 120px">
			</div>

            <?php if (count($menus) >= 2) { ?>
				<nav class="fusewp-nav-tab-wrapper nav-tab-wrapper">
                    <?php foreach ($menus as $menu) : ?>
                        <?php
                        $id                             = esc_attr(fusewpVar($menu, 'id', ''));
                        $url                            = esc_url_raw(! empty($menu['url']) ? $menu['url'] : add_query_arg('view', $id));
                        self::$parent_menu_url_map[$id] = $url;
                        ?>
                        <a href="<?php echo esc_url(remove_query_arg(wp_removable_query_args(), $url)); ?>" class="fusewp-nav-tab nav-tab<?php echo $id == $active_menu ? ' fusewp-nav-active' : '' ?>">
                            <?php echo esc_attr($menu['label']) ?>
						</a>
                    <?php endforeach; ?>
				</nav>
            <?php } ?>

			<div class="fwp-admin-banner__helplinks">
                <?php if (defined('FUSEWP_DETACH_LIBSODIUM')) : ?>
					<span>
                            <a rel="noopener" href="https://fusewp.com/support/" target="_blank">
                                <span class="dashicons dashicons-admin-users"></span> <?php echo __('Request Support', 'fusewp'); ?>
                            </a>
                        </span>
                <?php else: ?>
                    <span>
                            <a class="fusewp-right-nav-active" rel="noopener" href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=topmenu">
                                <span class="dashicons dashicons-admin-links"></span> <?php echo __('Premium Upgrade', 'fusewp'); ?>
                            </a>
                        </span>
                <?php endif; ?>
				<span>
                        <a rel="noopener" href="https://fusewp.com/docs/" target="_blank">
                            <span class="dashicons dashicons-book"></span> <?php echo __('Documentation', 'fusewp'); ?>
                        </a>
                    </span>
                <span>
                        <a rel="noopener" href="https://wordpress.org/support/view/plugin-reviews/fusewp?filter=5#postform" target="_blank">
                            <span class="dashicons dashicons-star-filled"></span> <?php echo __('Review', 'fusewp'); ?>
                        </a>
                    </span>
			</div>
		</div>
        <?php
    }

    public function settings_page_header_sub_menus($active_menu, $active_submenu)
    {
        $submenus = $this->header_submenu_tabs();

        if (count($submenus) < 2) {
            return;
        }

        $active_menu_url = self::$parent_menu_url_map[$active_menu];

        $submenus = wp_list_filter($submenus, ['parent' => $active_menu]);

        echo '<ul class="subsubsub">';

        foreach ($submenus as $submenu) {

            printf(
                '<li><a href="%s"%s>%s</a></li>',
                esc_url(add_query_arg('section', $submenu['id'], $active_menu_url)),
                $active_submenu == $submenu['id'] ? ' class="fusewp-current"' : '',
                $submenu['label']
            );
        }
        echo '</ul>';
    }

    public function active_menu_tab()
    {
        if (strpos(fusewpVarGET('page'), 'fusewp') !== false) {
            return isset($_GET['view']) ? sanitize_text_field($_GET['view']) : $this->default_header_menu();
        }

        return false;
    }

    public function active_submenu_tab()
    {
        if (strpos(fusewpVarGET('page'), 'fusewp') !== false) {

            $active_menu = $this->active_menu_tab();

            $submenu_tabs      = wp_list_filter($this->header_submenu_tabs(), ['parent' => $active_menu]);
            $first_submenu_tab = '';
            if ( ! empty($submenu_tabs)) {
                $first_submenu_tab = array_values($submenu_tabs)[0]['id'];
            }

            return isset($_GET['section']) && fusewpVarGET('view', 'general', true) == $active_menu ? sanitize_text_field($_GET['section']) : $first_submenu_tab;
        }

        return false;
    }

    public function admin_page_callback()
    {
        $active_menu = $this->active_menu_tab();

        $active_submenu = $this->active_submenu_tab();

        $this->settings_page_header($active_menu, $active_submenu);

        do_action('fusewp_admin_settings_page_' . $active_menu);

        do_action('fusewp_admin_settings_submenu_page_' . $active_menu . '_' . $active_submenu);

        do_action('fusewp_after_admin_settings_page', FUSEWP_SETTINGS_DB_OPTION_NAME);
    }

    public function custom_save_settings($data)
    {
        $data = ! is_array($data) ? [] : $data;

        $old_data = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);

        update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, array_replace($old_data, $data));
    }

    /** --------------------------------------------------------------- */

    /**
     * Adds admin body class to all admin pages created by the plugin.
     *
     * @param string $classes Space-separated list of CSS classes.
     *
     * @return string Filtered body classes.
     * @since 0.1.0
     *
     */
    public function add_admin_body_class($classes)
    {
        $current_screen = get_current_screen();

        if (empty ($current_screen)) return;

        if (false !== strpos($current_screen->id, 'fusewp')) {
            // Leave space on both sides so other plugins do not conflict.
            $classes .= ' fusewp-admin ';

            if (defined('FUSEWP_DETACH_LIBSODIUM')) {
                $classes .= ' fusewp-premium ';
            } else {
                $classes .= ' fusewp-lite ';
            }
        }

        return $classes;
    }

    public static function sidebar_args()
    {
        $sidebar_args = [
            [
                'section_title' => esc_html__('Upgrade to Pro', 'fusewp'),
                'content'       => self::pro_upsell(),
            ],
            [
                'section_title' => esc_html__('Need Support?', 'fusewp'),
                'content'       => self::sidebar_support_docs(),
            ]
        ];

        if (defined('FUSEWP_DETACH_LIBSODIUM')) {
            unset($sidebar_args[0]);
        }

        return $sidebar_args;
    }

    public static function pro_upsell()
    {
        $integrations = [
            esc_html__('Double-Optin Control', 'fusewp'),
            esc_html__('Custom Field Mapping', 'fusewp'),
            esc_html__('Assign Tag to Users', 'fusewp'),
            'WooCommerce Subscriptions Sync',
            'WooCommerce Memberships Sync',
            'Easy Digital Downloads Sync',
            'MemberPress Sync',
            'WPForms Sync',
            'Advanced Gravity Forms Sync',
            'ProfilePress Sync',
            'Paid Memberships Pro Sync',
            'Restrict Content Pro Sync',
            'LearnDash Sync',
            'LifterLMS Sync',
            'Tutor LMS Sync',
            'Advanced Custom Fields Sync'
        ];

        $upsell_url = 'https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=sidebar_upsell';

        $content = '<p>';
        $content .= sprintf(
            esc_html__('Enhance the power of FuseWP with the Pro version featuring integrations with many plugins. %sLearn more%s', 'fusewp'),
            '<a target="_blank" href="' . $upsell_url . '">', '</a>'
        );
        $content .= '</p>';

        $content .= '<ul>';

        foreach ($integrations as $integration) :
            $content .= sprintf('<li>%s</li>', $integration);
        endforeach;

        $content .= '</ul>';

        $content .= '<a href="' . $upsell_url . '" target="__blank" class="button-primary">' . esc_html__('Get FuseWP Pro →', 'fusewp') . '</a>';

        return $content;
    }

    public static function sidebar_support_docs()
    {
        $content = '<p>';

        $support_url = 'https://wordpress.org/support/plugin/fusewp/';

        if (defined('FUSEWP_DETACH_LIBSODIUM')) {
            $support_url = 'https://fusewp.com/support/';
        }

        $content .= sprintf(
            esc_html__('Whether you need help or have a new feature request, let us know. %sRequest Support%s', 'fusewp'),
            '<a class="fusewp-link" href="' . $support_url . '" target="_blank">', '</a>'
        );

        $content .= '</p>';

        $content .= '<p>';
        $content .= sprintf(
            esc_html__('Detailed documentation is also available on the plugin website. %sView Knowledge Base%s', 'fusewp'),
            '<a class="fusewp-link" href="https://fusewp.com/docs/" target="_blank">', '</a>'
        );

        $content .= '</p>';

        $content .= '<p>';
        $content .= sprintf(
            esc_html__('If you are enjoying FuseWP and find it useful, please consider leaving a ★★★★★ review on WordPress.org. %sLeave a Review%s', 'fusewp'),
            '<a class="fusewp-link" href="https://wordpress.org/support/plugin/fusewp/reviews/?filter=5#new-post" target="_blank">', '</a>'
        );
        $content .= '</p>';

        return $content;
    }

    /**
     * @param $message
     * @param string $type error, info, success, warning
     *
     * @return void
     */
    public function trigger_admin_notices($message, $type = 'error')
    {
        $class = "notice-$type";
        add_action('fusewp_admin_notices', function () use ($message, $class) {
            printf('<div class="notice %2$s is-dismissible"><p>%1$s</p></div>', $message, $class);
        });
    }
}
