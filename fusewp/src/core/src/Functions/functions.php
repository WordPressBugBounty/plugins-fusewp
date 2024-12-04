<?php

use FuseWP\Core\Core;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\IntegrationInterface;
use FuseWP\Core\Sync\Sources\AbstractSyncSource;

function fusewp_get_settings($key)
{
    $data = fusewp_cache_transform('fusewp_settings_data', function () {
        return get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
    });

    return fusewpVar($data, $key);
}

/**
 * Converts date/time which should be in UTC to timestamp.
 *
 * strtotime uses the default timezone set in PHP which may or may not be UTC.
 *
 * @param $time
 *
 * @return false|int
 */
function fusewp_strtotime_utc($time)
{
    return strtotime($time . ' UTC');
}

/**
 * Check if an admin settings page is FuseWP'
 *
 * @return bool
 */
function fusewp_is_admin_page()
{
    $fwp_admin_pages_slug = array(
        FUSEWP_SETTINGS_SETTINGS_SLUG,
        FUSEWP_SYNC_SETTINGS_SLUG
    );

    return (isset($_GET['page']) && in_array($_GET['page'], $fwp_admin_pages_slug));
}

/**
 * @param $bucket
 * @param $key
 * @param bool $default
 * @param bool $empty
 *
 * @return bool|mixed
 */
function fusewpVar($bucket, $key, $default = false, $empty = false)
{
    if ($empty) {
        return ! empty($bucket[$key]) ? $bucket[$key] : $default;
    }

    return isset($bucket[$key]) ? $bucket[$key] : $default;
}

function fusewpVarObj($bucket, $key, $default = false, $empty = false)
{
    if ($empty) {
        return ! empty($bucket->$key) ? $bucket->$key : $default;
    }

    return isset($bucket->$key) ? $bucket->$key : $default;
}

function fusewpVarPOST($key, $default = false, $empty = false)
{
    if ($empty) {
        return ! empty($_POST[$key]) ? $_POST[$key] : $default;
    }

    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function fusewpVarGET($key, $default = false, $empty = false)
{
    if ($empty) {
        return ! empty($_GET[$key]) ? $_GET[$key] : $default;
    }

    return isset($_GET[$key]) ? $_GET[$key] : $default;
}

function fusewp_sanitize_key($key)
{
    return str_replace('-', '_', sanitize_key($key));
}

/**
 * @param $integration_id
 *
 * @param bool $is_connected return only connected integrations
 *
 * @return AbstractIntegration[]|AbstractIntegration|false
 */
function fusewp_get_registered_integrations($integration_id = '', $is_connected = false)
{
    return fusewp_cache_transform(sprintf('fusewp_get_registered_integrations_%s_%s', $integration_id, (string)$is_connected), function () use ($integration_id, $is_connected) {
        $integrations = apply_filters('fusewp_registered_integrations', []);

        if ($is_connected) {
            $integrations = array_filter($integrations, function ($value) {
                /** @var IntegrationInterface $value */
                return $value->is_connected();
            });
        }

        if ( ! empty($integration_id)) {
            return fusewpVar($integrations, $integration_id);
        }

        return $integrations;
    });
}

/**
 * Get list of integrations with Sync support.
 *
 * @param string $integration_id
 *
 * @param bool $is_connected return only connected integrations
 *
 * @return AbstractIntegration[]|AbstractIntegration|false
 */
function fusewp_get_registered_sync_integrations($integration_id = '', $is_connected = false)
{
    $bucket = array_filter(fusewp_get_registered_integrations('', $is_connected), function (AbstractIntegration $integration) {
        return in_array(AbstractIntegration::SYNC_SUPPORT, $integration::features_support());
    });

    if ( ! empty($integration_id)) {
        return fusewpVar($bucket, $integration_id);
    }

    return $bucket;
}

/**
 * @param string $source_id
 *
 * @return AbstractSyncSource[]|AbstractSyncSource|false
 */
function fusewp_get_registered_sync_sources($source_id = '')
{
    return fusewp_cache_transform('fusewp_get_registered_sync_sources_' . $source_id, function () use ($source_id) {

        $sources = apply_filters('fusewp_registered_sync_sources', []);

        if ( ! empty($source_id)) {
            return fusewpVar($sources, $source_id);
        }

        return $sources;
    });
}

function fusewp_clean($var, $callback = 'sanitize_text_field')
{
    if (is_array($var)) {
        return array_map('fusewp_clean', $var);
    } else {
        return is_scalar($var) ? call_user_func($callback, $var) : $var;
    }
}

/**
 * @param $cache_key
 * @param $callback
 *
 * @return bool|mixed
 */
function fusewp_cache_transform($cache_key, $callback)
{
    if (is_customize_preview()) return $callback();

    static $cache_transform_bucket = [];

    $result = fusewpVar($cache_transform_bucket, $cache_key, false);

    if ( ! $result) {

        $result = $callback();

        $cache_transform_bucket[$cache_key] = $result;
    }

    return $result;
}

function fusewp_content_http_redirect($myURL)
{
    ?>
    <script type="text/javascript">
        window.location.href = "<?php echo esc_url($myURL);?>"
    </script>
    <meta http-equiv="refresh" content="0; url=<?php echo esc_url($myURL); ?>">
    Please wait while you are redirected...or
    <a href="<?php echo esc_url($myURL); ?>">Click Here</a> if you do not want to wait.
    <?php
}

/**
 * @param string $integration_id
 * @param string $error
 *
 * @return void
 */
function fusewp_log_error($integration_id, $error)
{
    if (empty($error)) return;

    global $wpdb;

    $wpdb->insert(
        Core::sync_log_db_table(),
        [
            'error_message' => $error,
            'integration'   => $integration_id,
            'date'          => current_time('mysql', true),
        ],
        [
            '%s',
            '%s',
            '%s'
        ]
    );
}

function fusewp_delete_error_log($log_id)
{
    global $wpdb;

    return $wpdb->delete(
        Core::sync_log_db_table(),
        ['id' => $log_id],
        ['%d']
    );
}

function fusewp_delete_all_error_log()
{
    global $wpdb;

    $table = Core::sync_log_db_table();

    return $wpdb->query("DELETE FROM $table");
}

/**
 * @param $template
 * @param $vars
 *
 * @return string|void
 */
function fusewp_render_view($template, $vars = [])
{
    $parentDir = dirname(__FILE__, 2) . '/Admin/SettingsPage/views/';

    $path = $parentDir . $template . '.php';

    extract($vars);
    ob_start();
    require apply_filters('fusewp_render_view', $path, $vars, $template, $parentDir);
    $output = apply_filters('fusewp_render_view_output', ob_get_clean(), $template, $vars, $parentDir);

    return $output;
}

/**
 * @param $source
 *
 * @return bool
 */
function fusewp_sync_rule_source_exists($source)
{
    global $wpdb;

    $table = Core::sync_rule_db_table();

    $result = $wpdb->get_var(
        $wpdb->prepare("SELECT source FROM $table WHERE source = %s", sanitize_text_field($source))
    );

    return ! is_null($result);
}

function fusewp_sync_get_real_source_id($source_id)
{
    return fusewp_cache_transform('fusewp_sync_get_real_source_id_' . $source_id, function () use ($source_id) {
        if (strpos($source_id, '|') !== false) {
            $source_id = fusewpVar(explode('|', $source_id), 0);
        }

        return $source_id;
    });
}

function fusewp_sync_get_source_item_id($source_id)
{
    return fusewp_cache_transform('fusewp_sync_get_source_item_id_' . $source_id, function () use ($source_id) {
        if (strpos($source_id, '|') !== false) {
            return fusewpVar(explode('|', $source_id), 1);
        }

        return false;
    });
}

/**
 * @param int $rule_id
 *
 * @return mixed
 */
function fusewp_sync_get_rule($rule_id)
{
    global $wpdb;

    $table = Core::sync_rule_db_table();

    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($rule_id))
    );
}

/**
 * @param int $rule_id
 *
 * @return int|false
 */
function fusewp_sync_delete_rule($rule_id)
{
    global $wpdb;

    return $wpdb->delete(
        Core::sync_rule_db_table(),
        ['id' => $rule_id],
        ['%d']
    );
}

/**
 * @param string $source
 * @param string $status could either be 'active', 'disabled' or 'any'
 *
 * @return mixed|null
 */
function fusewp_sync_get_rule_by_source($source, $status = 'active')
{
    return fusewp_cache_transform('fusewp_sync_get_rule_by_source_' . $source . '_' . $status, function () use ($source, $status) {

        global $wpdb;

        $table = Core::sync_rule_db_table();

        $sql = "SELECT * FROM {$table} WHERE source = %s";

        $replacement = [sanitize_text_field($source)];

        if ($status !== 'any' && in_array($status, ['active', 'disabled'])) {
            $sql           .= ' AND status = %s';
            $replacement[] = $status;
        }

        return $wpdb->get_row($wpdb->prepare($sql, $replacement), ARRAY_A);
    });
}

/**
 * @param mixed $postedData
 *
 * @return int|WP_Error|false
 */
function fusewp_add_sync_rule_settings($postedData)
{
    if (empty($postedData['fusewp_sync_source'])) {
        return new \WP_Error(
            'fusewp_sync_rule_source_empty',
            esc_html__('No Sync rule source has been selected.', 'fusewp')
        );
    }

    if (fusewp_sync_rule_source_exists($postedData['fusewp_sync_source'])) {

        return new \WP_Error(
            'fusewp_sync_rule_exist',
            esc_html__('Sync rule for the selected source already exist.', 'fusewp')
        );
    }

    global $wpdb;

    $insert = $wpdb->insert(
        Core::sync_rule_db_table(),
        [
            'source'       => sanitize_text_field(fusewpVar($postedData, 'fusewp_sync_source', '')),
            'destinations' => wp_json_encode(fusewp_clean(fusewpVar($postedData, 'fusewp_sync_destinations', ''))),
            'status'       => sanitize_text_field($postedData['sync_status'])
        ],
        [
            '%s',
            '%s',
            '%s'
        ]
    );

    return ! $insert ? false : $wpdb->insert_id;
}

/**
 * @param int $rule_id
 * @param mixed $postedData
 *
 * @return false|int
 */
function fusewp_update_sync_rule_settings($rule_id, $postedData)
{
    global $wpdb;

    $result = $wpdb->update(
        Core::sync_rule_db_table(),
        [
            'source'       => sanitize_text_field(fusewpVar($postedData, 'fusewp_sync_source', '')),
            'destinations' => wp_json_encode(fusewp_clean(fusewpVar($postedData, 'fusewp_sync_destinations', ''))),
            'status'       => sanitize_text_field($postedData['sync_status'])
        ],
        ['id' => intval($rule_id)],
        [
            '%s',
            '%s',
            '%s'
        ],
        ['%d']
    );

    return $result !== false ? $rule_id : $result;
}

/**
 * @param $rule_id
 * @param $status
 *
 * @return bool|int
 */
function fusewp_sync_update_rule_status($rule_id, $status)
{
    global $wpdb;

    if (in_array($status, ['active', 'disabled'])) {

        return $wpdb->update(
            Core::sync_rule_db_table(),
            ['status' => sanitize_text_field($status)],
            ['id' => $rule_id],
            ['%s'],
            ['%d']
        );
    }

    return false;
}

function fusewp_get_ip_address()
{
    $user_ip = '127.0.0.1';

    $keys = array(
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR',
    );

    foreach ($keys as $key) {
        // Bail if the key doesn't exists.
        if ( ! isset($_SERVER[$key])) {
            continue;
        }

        if ($key == 'HTTP_X_FORWARDED_FOR' && ! empty($_SERVER[$key])) {
            //to check ip is pass from proxy
            // can include more than 1 ip, first is the public one
            $_SERVER[$key] = explode(',', $_SERVER[$key]);
            $_SERVER[$key] = sanitize_text_field($_SERVER[$key][0]);
        }

        // Bail if the IP is not valid.
        if ( ! filter_var(wp_unslash(trim($_SERVER[$key])), FILTER_VALIDATE_IP)) {
            continue;
        }

        $user_ip = str_replace('::1', '127.0.0.1', sanitize_text_field($_SERVER[$key]));
    }

    return apply_filters('fusewp_ip_address', $user_ip);
}

function fusewp_is_boolean($maybe_bool)
{
    if (is_bool($maybe_bool)) return true;

    if (is_string($maybe_bool)) {
        $maybe_bool = strtolower($maybe_bool);

        $valid_boolean_values = array(
            'false',
            'true',
            '0',
            '1',
        );

        return in_array($maybe_bool, $valid_boolean_values, true);
    }

    if (is_int($maybe_bool)) return in_array($maybe_bool, array(0, 1), true);

    return false;
}

function fusewp_is_valid_data($value)
{
    return fusewp_is_boolean($value) || is_int($value) || is_numeric($value) || ! empty($value);
}

/**
 * Check if HTTP status code is successful.
 *
 * @param int $code
 *
 * @return bool
 */
function fusewp_is_http_code_success($code)
{
    $code = intval($code);

    return $code >= 200 && $code <= 299;
}

/**
 * @param $selected
 * @param $current
 * @param $display
 *
 * @return string|void
 */
function fusewp_selected($selected, $current = true, $display = true)
{
    if (is_array($selected)) {
        $result = in_array($current, $selected) ? ' selected="selected"' : '';
    } else {
        $result = selected($selected, $current, false);
    }

    if ( ! $display) return $result;

    echo esc_html($result);
}

function fusewp_is_premium()
{
    return class_exists('\FuseWP\Libsodium\Libsodium') &&
           defined('FUSEWP_DETACH_LIBSODIUM');
}

/**
 * Check if flag is set or exists.
 *
 * @param string $flag_id
 *
 * @return bool|mixed
 */
function fusewp_is_bulk_sync_flag_exists($flag_id)
{
    return in_array($flag_id, get_option('fusewp_bulk_sync_flag', []));
}

/**
 * Set a bulk sync flag
 *
 * @param string $flag_id
 *
 * @return bool
 */
function fusewp_set_bulk_sync_flag($flag_id)
{
    $old   = get_option('fusewp_bulk_sync_flag', []);
    $old[] = $flag_id;

    return update_option('fusewp_bulk_sync_flag', $old, false);
}

/**
 * Delete a bulk sync flag
 *
 * @param string $flag_id
 *
 * @return bool
 */
function fusewp_delete_bulk_sync_flag($flag_id)
{
    delete_site_option('pand-' . md5('fwp_bulk_sync_notice_' . $flag_id));

    $old = get_option('fusewp_bulk_sync_flag', []);

    return update_option('fusewp_bulk_sync_flag', array_diff($old, [$flag_id]), false);
}

function fusewp_set_time_limit($limit = 0)
{
    if (function_exists('set_time_limit') && false === strpos(ini_get('disable_functions'), 'set_time_limit') && ! ini_get('safe_mode')) {
        @set_time_limit($limit);
    }
}

function fusewp_do_admin_redirect($url)
{
    if ( ! headers_sent()) {
        wp_safe_redirect($url);
        exit;
    }

    fusewp_content_http_redirect($url);
}