<?php
/**
 * Plugin Name: FuseWP - Lite
 * Plugin URI: https://fusewp.com
 * Description: Connect WordPress to your email marketing software and CRM.
 * Version: 1.1.17.0
 * Author: FuseWP Team
 * Text Domain: fusewp
 * Author URI: https://fusewp.com
 * Domain Path: /languages
 * License: GPL2
 */

require __DIR__ . '/src/core/autoloader.php';
require __DIR__ . '/src/core/third-party/vendor/autoload.php';

define('FUSEWP_SYSTEM_FILE_PATH', __FILE__);
define('FUSEWP_VERSION_NUMBER', '1.1.17.0');

add_action('init', 'fusewp_fusewp_load_plugin_textdomain', 0);
function fusewp_fusewp_load_plugin_textdomain()
{
    load_plugin_textdomain('fusewp', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

FuseWP\Core\Core::init();