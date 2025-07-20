<?php

namespace FuseWP\Core\Integrations\Groundhogg;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;
use Groundhogg\Contact;
use Groundhogg\Preferences;

use function Groundhogg\after_form_submit_handler;
use function Groundhogg\get_contactdata;
use function Groundhogg\get_owners;

class SyncAction extends AbstractSyncAction
{
    protected $groundhoggInstance;

    /**
     * @param Groundhogg $groundhoggInstance
     */
    public function __construct($groundhoggInstance)
    {
        $this->groundhoggInstance = $groundhoggInstance;
    }

    public function get_integration_id()
    {
        return $this->groundhoggInstance->id;
    }

    private function get_owners()
    {
        $bucket = ['' => '&mdash;&mdash;&mdash;'];

        foreach (get_owners() as $owner) {
            $bucket[$owner->ID] = sprintf('%s (%s)', $owner->display_name, $owner->user_email);
        }

        return $bucket;
    }

    /**
     * @return array
     */
    public static function get_optin_status()
    {
        return [
            Preferences::CONFIRMED    => _x('Confirmed', 'optin_status', 'groundhogg'),
            Preferences::UNCONFIRMED  => _x('Unconfirmed', 'optin_status', 'groundhogg'),
            Preferences::UNSUBSCRIBED => _x('Unsubscribed', 'optin_status', 'groundhogg'),
            Preferences::WEEKLY       => _x('Subscribed Weekly', 'optin_status', 'groundhogg'),
            Preferences::MONTHLY      => _x('Subscribed Monthly', 'optin_status', 'groundhogg'),
        ];
    }

    /**
     * @param $index
     *
     * @return array
     */
    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Tag', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->groundhoggInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;')
                ->set_description(sprintf(
                    esc_html__("Select the tag to assign to contacts. Can't find the appropriate tag, %sclick here%s to add one on Groundhogg.", 'fusewp'),
                    '<a target="_blank" href="' . admin_url('admin.php?page=gh_tags') . '">', '</a>'
                )),
            (new Select($prefix('contact_owner'), esc_html__('Contact Owner', 'fusewp')))
                ->set_db_field_id('contact_owner')
                ->set_options($this->get_owners()),
            (new Select($prefix('optin_status'), esc_html__('Opt-in Status', 'fusewp')))
                ->set_db_field_id('optin_status')
                ->set_options($this->get_optin_status()),
            (new Custom($prefix('groundhogg_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=groundhogg_sync_destination_upsell" target="_blank">',
                            '</a>'
                        ) . '</p>';
                }),
        ];

        if (fusewp_is_premium()) {
            unset($fields[3]);
        }

        return $fields;
    }

    /**
     * @param $list_id
     * @param $index
     *
     * @return array
     */
    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->groundhoggInstance->title)
            ->set_integration_contact_fields($this->groundhoggInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    /**
     * Get default data for list fields
     */
    public function get_list_fields_default_data()
    {
        return [
            'custom_fields' => [
                'mappable_data'       => [
                    'first_name',
                    'last_name'
                ],
                'mappable_data_types' => [
                    'text',
                    'text'
                ],
                'field_values'        => [
                    'first_name',
                    'last_name'
                ]
            ]
        ];
    }

    /**
     * @param $custom_fields
     * @param MappingUserDataEntity $mappingUserDataEntity
     *
     * @return array
     */
    protected function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity)
    {
        $output = [];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $field_type = fusewpVar($mappable_data_types, $index);

                        $field_id = $field_value;

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($field_type == ContactFieldEntity::DATE_FIELD) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::DATETIME_FIELD) {
                            $data = gmdate('Y-m-d H:i:s', fusewp_strtotime_utc($data));
                        }

                        if ($field_type == ContactFieldEntity::NUMBER_FIELD) {
                            $data = absint($data);
                        }

                        if ($field_type == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = (array)$data;
                        }

                        if ($field_type != ContactFieldEntity::MULTISELECT_FIELD && is_array($data)) {
                            $data = implode(', ', $data);
                        }

                        $output[$field_id] = $data;
                    }
                }
            }
        }

        return $output;
    }

    /**
     * @param $list_id
     * @param $email_address
     * @param $mappingUserDataEntity
     * @param $custom_fields
     * @param $tags
     * @param $old_email_address
     *
     * @return bool
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $email = ! empty($old_email_address) && $old_email_address !== $email_address ? $old_email_address : $email_address;

            $owner        = $GLOBALS['fusewp_sync_destination'][$list_id]['contact_owner'];
            $optin_status = $GLOBALS['fusewp_sync_destination'][$list_id]['optin_status'];
            if (empty($optin_status)) $optin_status = Preferences::CONFIRMED;

            // This will create a new contact or fetch the one that already exists with the given email
            $contact = new Contact(['email' => $email]);

            // Prepare contact data
            $properties = apply_filters(
                'fusewp_groundhogg_subscription_parameters',
                array_filter(
                    array_merge(
                        ['email' => $email_address, 'owner_id' => $owner, 'optin_status' => $optin_status],
                        $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity)
                    ), 'fusewp_is_valid_data'),
                $this
            );

            if ($contact->exists()) {
                // update() only updates email, last_name, first_name, optin_status, owner_id/user_id
                $contact->update($properties);

                unset($properties['user_id']);
                unset($properties['owner_id']);
                unset($properties['optin_status']);
                unset($properties['first_name']);
                unset($properties['last_name']);
                unset($properties['email']);

                $countryTransform = fusewp_country_name_to_code($properties['country']);
                if ( ! empty($countryTransform)) {
                    $properties['country'] = $countryTransform;
                }

                foreach ($properties as $key => $value) {
                    $contact->update_meta($key, $value);
                }

                $contact->apply_tag($list_id);

                after_form_submit_handler($contact);

                /**
                 * useful for doing stuff with $contact eg
                 * $contact->set_marketing_consent();
                 * $contact->set_gdpr_consent();
                 */
                do_action('fusewp_groundhogg_after_subscribe', $contact, $list_id, $email_address);

                return true;
            }

            throw new \Exception('Groundhogg contact does not exist. something went wrong.');

        } catch (\Exception $e) {

            fusewp_log_error($this->groundhoggInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @param $list_id
     * @param $email_address
     *
     * @return bool
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        $contact = get_contactdata($email_address);

        if ($contact && $contact->exists()) {
            $contact->remove_tag($list_id);
        }

        return true;
    }
}
