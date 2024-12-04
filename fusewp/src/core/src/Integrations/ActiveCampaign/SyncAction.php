<?php

namespace FuseWP\Core\Integrations\ActiveCampaign;

use FuseWP\Core\Admin\Fields\Custom;
use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Integrations\ContactFieldEntity;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $activecampaignInstance;

    /**
     * @param ActiveCampaign $activecampaignInstance
     */
    public function __construct($activecampaignInstance)
    {
        $this->activecampaignInstance = $activecampaignInstance;
    }

    public function get_integration_id()
    {
        return $this->activecampaignInstance->id;
    }

    /**
     * @return array
     */
    public function get_tags()
    {
        $tags = [];

        try {

            $response = $this->activecampaignInstance->apiClass()->make_request('tags');

            if (isset($response['body']->tags)) {
                foreach ($response['body']->tags as $tag) {
                    $tags[$tag->id] = $tag->tag;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->activecampaignInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $tags;
    }

    /**
     * @return array
     */
    public function get_accounts()
    {
        $accounts = ['' => '&mdash;&mdash;&mdash;'];

        try {

            $response = $this->activecampaignInstance->apiClass()->make_request('accounts', ['count_deals' => 'false']);

            if (isset($response['body']->accounts)) {
                foreach ($response['body']->accounts as $account) {
                    $accounts[$account->id] = $account->name;
                }
            }

        } catch (\Exception $e) {
            fusewp_log_error($this->activecampaignInstance->id, __METHOD__ . ':' . $e->getMessage());
        }

        return $accounts;
    }

    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        $fields = [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Contact List', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->activecampaignInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;'),
            (new Select($prefix('account'), esc_html__('Select Account', 'fusewp')))
                ->set_db_field_id('account')
                ->set_options($this->get_accounts())
                ->set_description(esc_html__('Select the account to associate contacts to.', 'fusewp')),
            (new Select($prefix(self::TAGS_FIELD_ID), esc_html__('Tags', 'fusewp')))
                ->set_db_field_id(self::TAGS_FIELD_ID)
                ->set_is_multiple()
                ->set_options($this->get_tags())
                ->set_description(esc_html__('Select the tags to assign to contacts.', 'fusewp')),
            (new Custom($prefix('activecampaign_upsell'), esc_html__('Premium Features', 'fusewp')))
                ->set_content(function () {
                    return '<p>' . sprintf(
                            esc_html__('%sUpgrade to FuseWP Premium%s to assign tags to contact, select account to associate contacts to and map custom fields.', 'fusewp'),
                            '<a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=activecampaign_sync_destination_upsell" target="_blank">', '</a>'
                        ) . '</p>';
                })
        ];

        if ( ! fusewp_is_premium()) {
            unset($fields[1]);
            unset($fields[2]);
        } else {
            unset($fields[3]);
        }

        return $fields;
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields = [];

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->activecampaignInstance->title)
            ->set_integration_contact_fields($this->activecampaignInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

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
                    'fusewpFirstName',
                    'fusewpLastName'
                ]
            ]
        ];
    }

    public function transform_custom_field_data($custom_fields, MappingUserDataEntity $mappingUserDataEntity, $isFindJobTitle = false)
    {
        $output = [];

        if (is_array($custom_fields) && ! empty($custom_fields)) {

            $mappable_data       = fusewpVar($custom_fields, 'mappable_data', []);
            $mappable_data_types = fusewpVar($custom_fields, 'mappable_data_types', []);
            $field_values        = fusewpVar($custom_fields, 'field_values', []);

            if (is_array($field_values) && ! empty($field_values)) {

                foreach ($field_values as $index => $field_value) {

                    if ( ! empty($mappable_data[$index])) {

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if ($isFindJobTitle) {
                            if ($field_value == 'fusewpJobTitle') return $data;
                            continue;
                        }

                        if ($field_value == 'fusewpFirstName') {
                            $output['firstName'] = $data;
                            continue;
                        }

                        if ($field_value == 'fusewpLastName') {
                            $output['lastName'] = $data;
                            continue;
                        }

                        if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::DATE_FIELD) {
                            $data = gmdate('Y-m-d', fusewp_strtotime_utc($data));
                        }

                        if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::DATETIME_FIELD) {
                            $data = gmdate('c', fusewp_strtotime_utc($data));
                        }

                        if (fusewpVar($mappable_data_types, $index) == ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = (array)$data;
                        }

                        if (is_array($data) && fusewpVar($mappable_data_types, $index) != ContactFieldEntity::MULTISELECT_FIELD) {
                            $data = implode(', ', $data);
                        }

                        $output['fieldValues'][] = ['field' => $field_value, 'value' => $data];
                    }
                }
            }
        }

        return $output;
    }

    /**
     * {@inheritdoc}
     *
     */
    public function subscribe_user($list_id, $email_address, $mappingUserDataEntity, $custom_fields = [], $tags = '', $old_email_address = '')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        try {

            $parameters = [
                'contact' => [
                    'email' => $email_address
                ]
            ];

            $parameters['contact'] = array_merge(
                $parameters['contact'],
                array_filter(
                    $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity),
                    'fusewp_is_valid_data'
                )
            );

            $parameters = apply_filters(
                'fusewp_activecampaign_subscription_parameters',
                array_filter($parameters, 'fusewp_is_valid_data'),
                $this, $list_id, $email_address, $mappingUserDataEntity, $custom_fields, $tags, $old_email_address
            );

            if ( ! empty($old_email_address) && $email_address != $old_email_address) {

                $contact_id = $this->get_contact_id_by_email($old_email_address);

                $response = $this->activecampaignInstance->apiClass()->make_request(
                    sprintf('contacts/%d', $contact_id),
                    $parameters,
                    'put'
                );

            } else {

                $response = $this->activecampaignInstance->apiClass()->make_request(
                    'contact/sync',
                    $parameters,
                    'post'
                );
            }

            if (isset($response['body']->contact->id)) {

                $contact_id = $response['body']->contact->id;

                $this->update_contact_to_list($contact_id, $list_id);

                if (is_array($tags) && ! empty($tags)) {
                    foreach ($tags as $tag_id) {
                        $this->add_tag_to_contact($tag_id, $contact_id);
                    }
                }

                if ( ! empty($GLOBALS['fusewp_sync_destination'][$list_id]['account'])) {

                    $jobTitle = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity, true);

                    $account_id = $GLOBALS['fusewp_sync_destination'][$list_id]['account'];

                    $this->associate_contact_with_account($account_id, $contact_id, $jobTitle);
                }

                return true;
            }

            throw new \Exception(__METHOD__ . ':' . is_string($response) ? $response : wp_json_encode($response));

        } catch (\Exception $e) {
            fusewp_log_error($this->activecampaignInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe_user($list_id, $email_address)
    {
        $contact_id = $this->get_contact_id_by_email($email_address);

        if ($contact_id) {
            return $this->update_contact_to_list($contact_id, $list_id, 'unsubscribe');
        }

        return false;
    }

    protected function get_contact_id_by_email($email_address)
    {
        try {

            $response = $this->activecampaignInstance->apiClass()->make_request('contacts', ['email' => $email_address]);

            if (isset($response['body']->contacts[0]->id)) {
                return $response['body']->contacts[0]->id;
            }

        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * @param $contact_id
     * @param string $list_id
     * @param string $action
     *
     * @return bool
     */
    protected function update_contact_to_list($contact_id, $list_id, $action = 'subscribe')
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        try {

            $parameters = [
                'contactList' => [
                    'list'    => $list_id,
                    'contact' => $contact_id,
                    'status'  => $action == 'subscribe' ? '1' : '2'
                ]
            ];

            $response = $this->activecampaignInstance->apiClass()->make_request('contactLists', $parameters, 'post');

            return fusewp_is_http_code_success($response['status']);

        } catch (\Exception $e) {
            fusewp_log_error($this->activecampaignInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @param $tag_id
     * @param $contact_id
     *
     * @return bool
     */
    protected function add_tag_to_contact($tag_id, $contact_id)
    {
        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        if ( ! empty($tag_id) && ! empty($contact_id)) {

            try {

                $parameters = [
                    'contactTag' => [
                        'tag'     => $tag_id,
                        'contact' => $contact_id
                    ]
                ];

                $response = $this->activecampaignInstance->apiClass()->make_request('contactTags', $parameters, 'post');

                return fusewp_is_http_code_success($response['status']);

            } catch (\Exception $e) {
                fusewp_log_error($this->activecampaignInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);
            }
        }

        return false;
    }

    /**
     * @param $account_id
     * @param $contact_id
     * @param $jobTitle
     *
     * @return bool
     */
    protected function associate_contact_with_account($account_id, $contact_id, $jobTitle)
    {
        if (empty($account_id) || empty($contact_id)) return false;

        if ( ! is_string($jobTitle)) return false;

        $func_args = $this->get_sync_payload_json_args(func_get_args(), true);

        try {

            $parameters = array_filter([
                'accountContact' => [
                    'contact'  => $contact_id,
                    'account'  => $account_id,
                    'jobTitle' => $jobTitle
                ]
            ],
                'fusewp_is_valid_data'
            );

            $response = $this->activecampaignInstance->apiClass()->make_request('accountContacts', $parameters, 'post');

            return fusewp_is_http_code_success($response['status']);

        } catch (\Exception $e) {
            fusewp_log_error($this->activecampaignInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }
}