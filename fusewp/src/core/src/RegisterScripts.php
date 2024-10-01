<?php

namespace FuseWP\Core;

class RegisterScripts
{
    public static function suffix_asset($path, $ext = 'js')
    {
        $suffix = (defined('W3GUY_LOCAL') && W3GUY_LOCAL) || (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';

        return sprintf('%s%s.%s', $path, $suffix, $ext);
    }

    public function __construct()
    {
        add_action('admin_enqueue_scripts', array($this, 'admin_css'));
        add_action('admin_enqueue_scripts', [$this, 'admin_js']);
        add_action('wp_enqueue_scripts', array($this, 'public_css'));
        add_action('wp_enqueue_scripts', array($this, 'public_js'));
    }

    /**
     * Admin JS
     */
    public function admin_js()
    {
        if ( ! fusewp_is_admin_page()) return;

        wp_enqueue_script('jquery');
        wp_enqueue_script('underscore');
        wp_enqueue_script('wp-util');

        wp_enqueue_script('postbox');

        wp_enqueue_script('fusewp-select2', FUSEWP_ASSETS_URL . 'select2/select2.min.js', ['jquery'], FUSEWP_VERSION_NUMBER);
        wp_enqueue_script('fusewp-jquery-modal', FUSEWP_ASSETS_URL . 'jquery-modal/jquery.modal.min.js', ['jquery'], FUSEWP_VERSION_NUMBER);
        wp_enqueue_script('fusewp-admin-scripts', FUSEWP_ASSETS_URL . $this->suffix_asset('js/admin'), ['jquery'], FUSEWP_VERSION_NUMBER);
        wp_enqueue_script('fusewp-sync-builder', FUSEWP_ASSETS_URL . $this->suffix_asset('js/sync-builder'), ['jquery', 'wp-util'], FUSEWP_VERSION_NUMBER);

        $l10n_args = [
            'confirm_delete'                   => esc_html__('Are you sure?', 'fusewp'),
            'nonce'                            => wp_create_nonce('fusewp_csrf'),
            'sync_page_no_source_message'      => esc_html__('No source has been selected. Add one before you can set up sync destination.', 'fusewp'),
            'sync_page_no_destination_message' => sprintf(esc_html__('No destination added. Click the %s+ Add Destination%s button to add one.', 'fusewp'), '<strong>', '</strong>'),
            'bulk_sync_confirm_message'        => esc_html__('Are you sure you want to bulk-sync existing users? This might take some time depending on the number of your website users.', 'fusewp')
        ];

        wp_localize_script('fusewp-admin-scripts', 'fusewp_obj', apply_filters('fusewp_admin_js_localize_args', $l10n_args));
    }

    /**
     * Enqueue public scripts and styles.
     */
    public function public_js()
    {

    }

    public function admin_css()
    {
        if ( ! fusewp_is_admin_page()) return;

        wp_enqueue_style('fusewp-select2', FUSEWP_ASSETS_URL . 'select2/select2.min.css', [], FUSEWP_VERSION_NUMBER);
        wp_enqueue_style('fusewp-jquery-modal', FUSEWP_ASSETS_URL . 'jquery-modal/jquery.modal.min.css', [], FUSEWP_VERSION_NUMBER);
        wp_enqueue_style('fusewp-admin', FUSEWP_ASSETS_URL . 'css/admin.css', [], FUSEWP_VERSION_NUMBER);
        wp_enqueue_style('fusewp-hint', FUSEWP_ASSETS_URL . 'css/hint.min.css', [], FUSEWP_VERSION_NUMBER);
    }

    /**
     * Front-end CSS
     */
    public function public_css()
    {

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
