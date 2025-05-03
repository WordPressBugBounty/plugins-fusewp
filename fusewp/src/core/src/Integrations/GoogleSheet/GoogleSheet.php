<?php

namespace FuseWP\Core\Integrations\GoogleSheet;

use Authifly\Provider\Google as AuthiflyGoogle;
use Authifly\Storage\OAuthCredentialStorage;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class GoogleSheet extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'google_sheet';

        $this->title = 'Google Sheets';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/google-sheet.svg';

        $this->adminSettingsPageInstance = new AdminSettingsPage($this);

        parent::__construct();

        add_action('fusewp_cache_clearing_' . $this->id, [$this, 'clear_cache']);
    }

    /**
     * @return array
     */
    public static function features_support()
    {
        return [self::SYNC_SUPPORT, self::CACHE_CLEARING_SUPPORT];
    }

    /**
     * @return bool
     */
    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'client_id')) &&
                   ! empty(fusewpVar($settings, 'client_secret')) &&
                   ! empty(fusewpVar($settings, 'access_token'));
        });
    }

    /**
     * @return bool|mixed
     */
    public function is_credentials_saved()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {
            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'client_id')) && ! empty(fusewpVar($settings, 'client_secret'));
        });
    }

    /**
     * @return string
     */
    public function callback_url()
    {
        return add_query_arg(['fusewpauth' => $this->id], FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
    }

    public function clear_cache()
    {
        delete_transient('fusewp_google_sheet_files');
        delete_transient('fusewp_google_sheets_columns');
        delete_transient('fusewp_google_sheet_files_sheets');
    }

    public function connection_settings()
    {
        return $this->adminSettingsPageInstance->connection_settings();
    }

    /**
     * @return mixed
     */
    public function get_email_list()
    {
        try {

            $lists = get_transient('fusewp_google_sheet_files');

            if (empty($lists)) {

                $lists = [];

                $response = $this->apiClass('drive')->apiRequest(
                    'drive/v3/files?pageSize=1000&q=' . rawurlencode('mimeType="application/vnd.google-apps.spreadsheet" and trashed=false')
                );

                // Validate API response
                if (is_object($response) && isset($response->kind, $response->files) && $response->kind === 'drive#fileList') {

                    foreach ($response->files as $file) {
                        if (isset($file->id, $file->name)) {
                            $lists[$file->id] = $file->name;
                        }
                    }

                    set_transient('fusewp_google_sheet_files', $lists, 3 * DAY_IN_SECONDS);

                } else {
                    fusewp_log_error($this->id, 'Invalid API response format');

                    return [];
                }
            }

            return $lists;

        } catch (\Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            // Optionally, delete the transient to avoid serving stale data
            delete_transient('fusewp_google_sheet_files');

            return [];
        }
    }

    public function get_spreadsheet_sheets($spreadsheet_id)
    {
        if (empty($spreadsheet_id)) {
            return [];
        }

        try {

            $bucket = get_transient('fusewp_google_sheet_files_sheets');

            if ( ! is_array($bucket)) {
                $bucket = [];
            }

            // Check if cached data exists for the current $spreadsheet_id
            if (empty($bucket[$spreadsheet_id])) {

                $response = $this->apiClass()->apiRequest($spreadsheet_id . '?includeGridData=false');

                if (is_object($response) && isset($response->sheets) && is_array($response->sheets)) {

                    $bucket[$spreadsheet_id] = [];

                    // Parse API response and populate the cache
                    foreach ($response->sheets as $sheet) {
                        if (isset($sheet->properties->title)) {
                            $bucket[$spreadsheet_id][$sheet->properties->title] = $sheet->properties->title;
                        }
                    }

                    set_transient('fusewp_google_sheet_files_sheets', $bucket, 3 * DAY_IN_SECONDS);

                } else {

                    fusewp_log_error($this->id, __METHOD__ . ':' . 'Invalid API response format');

                    return [];
                }
            }

            return $bucket[$spreadsheet_id];

        } catch (\Exception $e) {

            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());

            if (isset($bucket[$spreadsheet_id])) {
                unset($bucket[$spreadsheet_id]);
                set_transient('fusewp_google_sheet_files_sheets', $bucket, 3 * DAY_IN_SECONDS);
            }
        }

        return [];
    }

    /**
     * @param $list_id
     *
     * @return array
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        $sheet_name = fusewpVarPOST('GoogleSheetConnect_file_sheets', '');

        $fields = $this->get_sheet_header_columns($list_id, $sheet_name);

        foreach ($fields as $field) {
            $bucket[] = (new ContactFieldEntity())
                ->set_id($field)
                ->set_name($field)
                ->set_data_type(ContactFieldEntity::TEXT_FIELD);
        }

        return $bucket;
    }

    public function get_sheet_header_columns($sheet_file, $sheet_name)
    {
        if (empty($sheet_file)) {
            return [];
        }

        if (empty($sheet_name)) {
            $sheets = $this->get_spreadsheet_sheets($sheet_file);
            if (is_array($sheets) && ! empty($sheets)) {
                $sheet_name = array_shift($sheets);
            } else {
                return [];
            }
        }

        $cache_key = sprintf('%s_%s', $sheet_file, $sheet_name);

        $columns = get_transient('fusewp_google_sheets_columns');
        if ( ! is_array($columns)) {
            $columns = [];
        }

        if (empty($columns[$cache_key])) {
            try {
                $response = $this->apiClass()->apiRequest(sprintf('%s/values/%s!1:1', $sheet_file,
                    rawurlencode($sheet_name)));

                if (is_object($response) && isset($response->values[0]) && is_array($response->values[0])) {

                    $columns[$cache_key] = [];

                    foreach ($response->values[0] as $index => $field) {
                        $columns[$cache_key][$index] = $field;
                    }

                    set_transient('fusewp_google_sheets_columns', $columns, 3 * DAY_IN_SECONDS);

                } else {
                    fusewp_log_error($this->id, __METHOD__ . ':' . 'Invalid API response format');
                }
            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());

                if (isset($columns[$cache_key])) {
                    unset($columns[$cache_key]);
                    set_transient('fusewp_google_sheets_columns', $columns, 3 * DAY_IN_SECONDS);
                }
            }
        }

        return $columns[$cache_key] ?? [];
    }

    /**
     * @return mixed
     */
    public function get_sync_action()
    {
        return new SyncAction($this);
    }

    /**
     * @param $apiBaseType
     *
     * @return AuthiflyGoogle
     */
    public function apiClass($apiBaseType = 'sheet')
    {
        $settings = $this->get_settings();

        $client_id     = fusewpVar($settings, 'client_id');
        $client_secret = fusewpVar($settings, 'client_secret');
        $access_token  = fusewpVar($settings, 'access_token');
        $refresh_token = fusewpVar($settings, 'refresh_token');
        $expires_at    = fusewpVar($settings, 'expires_at');

        if (empty($access_token)) {
            throw new \Exception(__('Google access token not found.', 'fusewp'));
        }

        $this->get_connect_url();

        if (empty($refresh_token)) {
            throw new \Exception(__('Google refresh token not found.', 'fusewp'));
        }

        $config = [
            'callback' => self::callback_url(),
            'keys'     => ['id' => $client_id, 'secret' => $client_secret]
        ];

        $instance = new AuthiflyGoogle($config, null,
            new OAuthCredentialStorage([
                'google.access_token'  => $access_token,
                'google.refresh_token' => $refresh_token,
                'google.expires_at'    => $expires_at,
            ])
        );

        if ($instance->hasAccessTokenExpired()) {

            try {

                $instance->refreshAccessToken();

                $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
                $old_data    = get_option($option_name, []);
                $expires_at  = $this->oauth_expires_at_transform($instance->getStorage()->get('google.expires_at'));

                // refreshtoken is the same as google oauth does not return a new one on token refresh
                // See https://developers.google.com/identity/protocols/oauth2#5.-refresh-the-access-token,-if-necessary.
                $old_data[$this->id]['access_token'] = $instance->getStorage()->get('google.access_token');
                $old_data[$this->id]['expires_at']   = $expires_at;

                update_option($option_name, $old_data);

                $instance = new AuthiflyGoogle($config, null,
                    new OAuthCredentialStorage([
                        'google.access_token'  => $instance->getStorage()->get('google.access_token'),
                        'google.refresh_token' => $instance->getStorage()->get('google.refresh_token'),
                        'google.expires_at'    => $expires_at,
                    ]));

            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
            }
        }

        if ('sheet' === $apiBaseType) {
            $instance->apiBaseUrl = 'https://sheets.googleapis.com/v4/spreadsheets/';
        }

        return $instance;
    }

    public static function get_instance()
    {
        if (fusewp_is_premium()) {
            return new self();
        }

        return false;
    }
}
