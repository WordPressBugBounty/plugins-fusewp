<?php

namespace FuseWP\Core\Integrations\Salesforce;

use Authifly\Exception\InvalidAccessTokenException;
use Authifly\Provider\Salesforce as AuthiflySalesforce;
use Authifly\Storage\OAuthCredentialStorage;
use Exception;
use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Salesforce extends AbstractIntegration
{
    protected $adminSettingsPageInstance;

    public function __construct()
    {
        $this->id = 'salesforce';

        $this->title = 'Salesforce';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/salesforce-integration.svg';

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
     * @return mixed
     */
    public function is_connected()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {

            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'consumer_key')) &&
                   ! empty(fusewpVar($settings, 'consumer_secret')) &&
                   ! empty(fusewpVar($settings, 'access_token'));
        });
    }

    public function clear_cache()
    {
        $this->delete_transients_by_prefix('fusewp_salesforce_contact_fields_');
        $this->delete_transients_by_prefix('fusewp_salesforce_user_id_');
        delete_transient('fusewp_salesforce_email_list');
        delete_transient('fusewp_salesforce_topics');
    }

    public function is_credentials_saved()
    {
        return fusewp_cache_transform('fwp_integration_' . $this->id, function () {
            $settings = $this->get_settings();

            return ! empty(fusewpVar($settings, 'consumer_key')) && ! empty(fusewpVar($settings, 'consumer_secret'));
        });
    }

    /**
     * @return string
     */
    public function connection_settings()
    {
        return $this->adminSettingsPageInstance->connection_settings();
    }

    /**
     * @return mixed
     */
    public function get_email_list()
    {
        $transient_key = 'fusewp_salesforce_email_list';

        $cached_data = get_transient($transient_key);

        if ($cached_data !== false) return $cached_data;

        $bucket = [];

        try {
            $standard = [
                'Account'     => esc_html__('Account', 'fusewp'),
                'Campaign'    => esc_html__('Campaign', 'fusewp'),
                'Case'        => esc_html__('Case', 'fusewp'),
                'Contact'     => esc_html__('Contact', 'fusewp'),
                'Lead'        => esc_html__('Lead', 'fusewp'),
                'Opportunity' => esc_html__('Opportunity', 'fusewp'),
                'Product2'    => esc_html__('Product', 'fusewp'),
            ];

            // Make the API request
            $response = $this->makeRequest('sobjects');

            $objectBucket = [];

            if (isset($response->sobjects) && is_array($response->sobjects)) {
                foreach ($response->sobjects as $sobject) {
                    // Process the response
                    if ($sobject->createable && $sobject->layoutable) {
                        $objectBucket[$sobject->name] = $sobject->label;
                    }
                }
            }

            // Merge standard and fetched objects to form the final list
            $bucket = array_merge(array_intersect_key($standard, $objectBucket), $objectBucket);

            set_transient($transient_key, $bucket, DAY_IN_SECONDS);

        } catch (Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

    /**
     * @param string $list_id
     *
     * @return mixed
     */
    public function get_contact_fields($list_id = '')
    {
        // Define a unique transient key using $list_id directly
        $transient_key = 'fusewp_salesforce_contact_fields_' . $list_id;

        $cached_data = get_transient($transient_key);

        if ($cached_data !== false) return $cached_data;

        $bucket = [];

        try {
            // Make the API request
            $response = $this->makeRequest('sobjects/' . $list_id . '/describe');

            if (isset($response->fields) && is_array($response->fields)) {
                foreach ($response->fields as $field) {
                    // Skip fields with those parameters (they are not available for filling)
                    if ( ! $field->createable || $field->deprecatedAndHidden) {
                        continue;
                    }

                    // Determine field type
                    switch ($field->type) {
                        case 'date':
                            $data_type = ContactFieldEntity::DATE_FIELD;
                            break;
                        case 'datetime':
                            $data_type = ContactFieldEntity::DATETIME_FIELD;
                            break;
                        default:
                            $data_type = ContactFieldEntity::TEXT_FIELD;
                    }

                    // Build the bucket with ContactFieldEntity objects
                    $bucket[] = (new ContactFieldEntity())
                        ->set_id($field->name)
                        ->set_name($field->label)
                        ->set_data_type($data_type);
                }
            }

            set_transient($transient_key, $bucket, DAY_IN_SECONDS);

        } catch (Exception $e) {
            // Log any exceptions that occur during the process
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $bucket;
    }

    /**
     * @return array
     */
    public function get_topics()
    {
        // Define a unique transient key
        $transient_key = 'fusewp_salesforce_topics';

        $cached_data = get_transient($transient_key);

        if ($cached_data !== false) return $cached_data;

        $topics = [];

        try {

            $query = 'query?q=' . urlencode("SELECT Id, Name FROM Topic ORDER BY Name ASC");

            do {
                $body = $this->makeRequest($query);

                if ( ! empty($body->records)) {
                    foreach ($body->records as $topic) {
                        $topics[$topic->Id] = $topic->Name;
                    }
                }

                // For pagination, extract just the part after /services/data/v57.0/
                $query = null;

                if ( ! empty($body->nextRecordsUrl)) {
                    // Remove the /services/data/vXX.X/ prefix
                    $query = preg_replace('|^/services/data/v[0-9.]+/|', '', $body->nextRecordsUrl);
                }

            } while ($query);


            // Cache the result in a transient for 12 hours
            set_transient($transient_key, $topics, DAY_IN_SECONDS);

        } catch (Exception $e) {
            fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $topics;
    }

    /**
     * @return SyncAction
     */
    public function get_sync_action()
    {
        return new SyncAction($this);
    }

    /**
     * @return string
     */
    public function callback_url()
    {
        return add_query_arg(['fusewpauth' => $this->id], FUSEWP_SETTINGS_GENERAL_SETTINGS_PAGE);
    }

    public function salesforceInstance()
    {
        $settings = $this->get_settings();

        $consumer_key    = fusewpVar($settings, 'consumer_key');
        $consumer_secret = fusewpVar($settings, 'consumer_secret');
        $access_token    = fusewpVar($settings, 'access_token');
        $refresh_token   = fusewpVar($settings, 'refresh_token');
        $instance_url    = fusewpVar($settings, 'instance_url');

        if (empty($access_token)) {
            throw new Exception(__('Salesforce access token not found.', 'fusewp'));
        }

        if (empty($refresh_token)) {
            throw new Exception(__('Salesforce refresh token not found.', 'fusewp'));
        }

        $api_version = apply_filters('fusewp_salesforce_rest_api_version', '63.0');

        $config = [
            'callback'   => self::callback_url(),
            'keys'       => ['id' => $consumer_key, 'secret' => $consumer_secret],
            'apiBaseUrl' => rtrim($instance_url, '/') . '/services/data/v' . $api_version . '/',
        ];

        return new AuthiflySalesforce($config, null,
            new OAuthCredentialStorage([
                'salesforce.access_token'  => $access_token,
                'salesforce.refresh_token' => $refresh_token,
            ])
        );
    }

    /**
     * @throws Exception
     */
    public function makeRequest($url, $method = 'GET', $parameters = [], $headers = [])
    {
        $instance = $this->salesforceInstance();

        try {

            return $instance->apiRequest($url, $method, $parameters, $headers);

        } catch (InvalidAccessTokenException $e) {

            if (401 == $e->getCode()) {

                $instance->refreshAccessToken();

                $option_name = FUSEWP_SETTINGS_DB_OPTION_NAME;
                $old_data    = get_option($option_name, []);

                $old_data[$this->id]['access_token']  = $instance->getStorage()->get('salesforce.access_token');
                $old_data[$this->id]['refresh_token'] = $instance->getStorage()->get('salesforce.refresh_token');

                update_option($option_name, $old_data);

                return $this->salesforceInstance()->apiRequest($url, $method, $parameters, $headers);
            }

            throw new InvalidAccessTokenException($e->getMessage(), $e->getCode());
        }
    }

    public static function get_instance()
    {
        if (fusewp_is_premium()) {
            return new self();
        }

        return false;
    }
}
