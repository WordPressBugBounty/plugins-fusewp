<?php

namespace FuseWP\Core\Integrations\Groundhogg;

use FuseWP\Core\Integrations\AbstractIntegration;
use FuseWP\Core\Integrations\ContactFieldEntity;

class Groundhogg extends AbstractIntegration
{
    public function __construct()
    {
        $this->id = 'groundhogg';

        $this->title = 'Groundhogg';

        $this->logo_url = FUSEWP_ASSETS_URL . 'images/groundhogg-integration.png';

        parent::__construct();
    }

    /**
     * @return array
     */
    public static function features_support()
    {
        return [self::SYNC_SUPPORT];
    }

    /**
     * @return bool
     */
    public function is_connected()
    {
        return class_exists('\Groundhogg\Plugin') && function_exists('\Groundhogg\get_contactdata');
    }

    /**
     * @return string
     */
    public function connection_settings()
    {
        $message = $this->is_connected() ? esc_html__('Connected because Groundhogg is installed and active on this website', 'fusewp') :
            esc_html__('Not connected because Groundhogg is not activated on this website', 'fusewp');

        return sprintf('<p>%s</p>', $message);
    }

    /**
     * Get available tags as email lists
     */
    public function get_email_list()
    {
        $lists = [];

        if ($this->is_connected()) {

            try {

                $tags = \Groundhogg\get_db('tags')->query();

                foreach ($tags as $tag) {
                    $lists[$tag->tag_id] = $tag->tag_name;
                }

            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
        }

        return $lists;
    }

    /**
     * Get available contact fields
     */
    public function get_contact_fields($list_id = '')
    {
        $bucket = [];

        if ($this->is_connected()) {

            // https://help.groundhogg.io/article/877-list-of-replacement-codes
            $default = [
                'first_name'              => esc_html__('First Name', 'fusewp'),
                'last_name'               => esc_html__('Last Name', 'fusewp'),
                'primary_phone'           => esc_html__('Primary Phone', 'fusewp'),
                'primary_phone_extension' => esc_html__('Primary Phone Ext.', 'fusewp'),
                'mobile_phone'            => esc_html__('Mobile Phone', 'fusewp'),
                'birthday'                => esc_html__('Birthday', 'fusewp'),
                'company_name'            => esc_html__('Company', 'fusewp'),
                'company_website'         => esc_html__('Company Website', 'fusewp'),
                'company_address'         => esc_html__('Company Address', 'fusewp'),
                'company_phone'           => esc_html__('Company Phone', 'fusewp'),
                'job_title'               => esc_html__('Job Title', 'fusewp'),
                'street_address_1'        => esc_html__('Address Line 1', 'fusewp'),
                'street_address_2'        => esc_html__('Address Line 2', 'fusewp'),
                'city'                    => esc_html__('City', 'fusewp'),
                'postal_zip'              => esc_html__('Postal/Zip Code', 'fusewp'),
                'region'                  => esc_html__('State/Region', 'fusewp'),
                'country'                 => esc_html__('Country', 'fusewp'),
            ];

            foreach ($default as $key => $value) {

                $data_type = ContactFieldEntity::TEXT_FIELD;

                if ($key === 'birthday') {
                    $data_type = ContactFieldEntity::DATE_FIELD;
                }

                $bucket[] = (new ContactFieldEntity())
                    ->set_id($key)
                    ->set_name($value)
                    ->set_data_type($data_type);
            }

            try {

                $custom_fields = \Groundhogg\Properties::instance()->get_fields();

                foreach ($custom_fields as $field) {
                    $data_type = ContactFieldEntity::TEXT_FIELD;

                    switch ($field['type']) {
                        case 'date':
                            $data_type = ContactFieldEntity::DATE_FIELD;
                            break;
                        case 'datetime':
                            $data_type = ContactFieldEntity::DATETIME_FIELD;
                            break;
                        case 'number':
                            $data_type = ContactFieldEntity::NUMBER_FIELD;
                            break;
                    }

                    $bucket[] = (new ContactFieldEntity())
                        ->set_id($field['name'])
                        ->set_name($field['label'])
                        ->set_data_type($data_type);
                }
            } catch (\Exception $e) {
                fusewp_log_error($this->id, __METHOD__ . ':' . $e->getMessage());
            }
        }

        return $bucket;
    }

    /**
     * Get sync action instance
     */
    public function get_sync_action()
    {
        return new SyncAction($this);
    }
}
