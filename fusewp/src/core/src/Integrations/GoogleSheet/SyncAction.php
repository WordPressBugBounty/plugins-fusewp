<?php

namespace FuseWP\Core\Integrations\GoogleSheet;

use FuseWP\Core\Admin\Fields\FieldMap;
use FuseWP\Core\Admin\Fields\Select;
use FuseWP\Core\Integrations\AbstractSyncAction;
use FuseWP\Core\Sync\Sources\MappingUserDataEntity;

class SyncAction extends AbstractSyncAction
{
    protected $googleSheetInstance;

    /**
     * @param GoogleSheet $googleSheetInstance
     */
    public function __construct($googleSheetInstance)
    {
        $this->googleSheetInstance = $googleSheetInstance;
    }

    /**
     * @return mixed
     */
    public function get_integration_id()
    {
        return $this->googleSheetInstance->id;
    }

    /**
     * @param $index
     *
     * @return mixed
     */
    public function get_fields($index)
    {
        $prefix = $this->get_field_name($index);

        return [
            (new Select($prefix(self::EMAIL_LIST_FIELD_ID), esc_html__('Select Spreadsheet File', 'fusewp')))
                ->set_db_field_id(self::EMAIL_LIST_FIELD_ID)
                ->set_classes(['fusewp-sync-list-select'])
                ->set_options($this->googleSheetInstance->get_email_list())
                ->set_required()
                ->set_placeholder('&mdash;&mdash;&mdash;')
        ];
    }

    public function get_list_fields($list_id = '', $index = '')
    {
        $prefix = $this->get_field_name($index);

        $fields[] = (new Select($prefix('gsheet_file_sheets'), esc_html__('Select Sheet', 'fusewp')))
            ->set_db_field_id('gsheet_file_sheets')
            ->set_options($this->googleSheetInstance->get_spreadsheet_sheets($list_id))
            ->set_required()
            ->set_placeholder('&mdash;&mdash;&mdash;');

        $fields[] = (new FieldMap($prefix(self::CUSTOM_FIELDS_FIELD_ID), esc_html__('Map Custom Fields', 'fusewp')))
            ->set_db_field_id(self::CUSTOM_FIELDS_FIELD_ID)
            ->set_integration_name($this->googleSheetInstance->title)
            ->set_integration_contact_fields($this->googleSheetInstance->get_contact_fields($list_id))
            ->set_mappable_data($this->get_mappable_data());

        return $fields;
    }

    public function get_list_fields_default_data()
    {
        return [
            'custom_fields' => [
                'mappable_data'       => [
                    'first_name',
                    'last_name',
                ],
                'mappable_data_types' => [
                    'text',
                    'text',
                ],
                'field_values'        => [
                    '',
                    '',
                ]
            ]
        ];
    }

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

                        $data = $mappingUserDataEntity->get($mappable_data[$index]);

                        if (fusewp_is_valid_data($data)) {
                            $output[$field_value] = $data;
                        }
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

        $sheet_name = $GLOBALS['fusewp_sync_destination'][$list_id]['gsheet_file_sheets'] ?? 'false';

        try {

            $headers = $this->googleSheetInstance->get_sheet_header_columns($list_id, $sheet_name);

            $custom_data = $this->transform_custom_field_data($custom_fields, $mappingUserDataEntity);

            $valueArray = [];

            $lastHeaderColumnAlphabetKey = 'A';

            foreach ($headers as $header) {

                $data = '';

                if ( ! empty($custom_data[$header])) {
                    $data .= $custom_data[$header];
                }

                // https://stackoverflow.com/questions/3567180/how-to-increment-letters-like-numbers-in-php
                $lastHeaderColumnAlphabetKey++;

                $valueArray[] = $data;
            }

            $properties = apply_filters(
                'fusewp_google_sheet_subscription_parameters',
                ['values' => [$valueArray]],
                $this
            );

            // we don't do anything if email is changed/updated.

            $response = $this->googleSheetInstance->apiClass()->apiRequest(
                sprintf('%s/values/%s!A:%s:append?valueInputOption=USER_ENTERED',
                    $list_id,
                    $sheet_name,
                    $lastHeaderColumnAlphabetKey
                ),
                'POST',
                $properties,
                ['Content-Type' => 'application/json']
            );

            return isset($response->spreadsheetId);

        } catch (\Exception $e) {

            fusewp_log_error($this->googleSheetInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

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
        $func_args = $this->get_sync_payload_json_args(func_get_args());

        $sheet_name = $GLOBALS['fusewp_sync_destination'][$list_id]['gsheet_file_sheets'] ?? 'false';

        try {
            // Find email in the spreadsheet
            $email_datas = $this->find_email_in_spreadsheet($list_id, $email_address, $sheet_name);

            if ( ! empty($email_datas) && is_array($email_datas)) {

                foreach ($email_datas as $email_data) {

                    $row_index  = $email_data['row_index'];
                    $headers    = $email_data['headers'];
                    $sheet_name = $email_data['sheet_name'];

                    // Determine the last column letter
                    $last_column = chr(65 + count($headers) - 1);

                    // Clear the row data
                    $empty_row = array_fill(0, count($headers), "");

                    $clear_properties = [
                        'values' => [$empty_row]
                    ];

                    $this->googleSheetInstance->apiClass()->apiRequest(
                        sprintf('%s/values/%s!A%d:%s%d?valueInputOption=USER_ENTERED', $list_id, $sheet_name, $row_index, $last_column, $row_index),
                        'PUT',
                        $clear_properties,
                        ['Content-Type' => 'application/json']
                    );
                }

                return true;
            }

            // Email not found, already unsubscribed
            return false;

        } catch (\Exception $e) {
            fusewp_log_error($this->googleSheetInstance->id, __METHOD__ . ':' . $e->getMessage() . '|' . $func_args);

            return false;
        }
    }

    /**
     * @param $list_id
     * @param $email_address
     * @param $sheet_name
     *
     * @return array|false
     */
    protected function find_email_in_spreadsheet($list_id, $email_address, $sheet_name)
    {
        if (empty($list_id) || empty($email_address)) return false;

        try {

            // Get headers to find the email column
            $headers = $this->googleSheetInstance->get_sheet_header_columns($list_id, $sheet_name);

            if ( ! is_array($headers) || empty($headers)) {
                return false;
            }

            $email_column_index = array_search('Email', $headers);

            if ($email_column_index === false || ! is_int($email_column_index)) {
                return false; // Couldn't find email column
            }

            // Get all values from the sheet
            $response = $this->googleSheetInstance->apiClass()->apiRequest(
                sprintf('%s/values/%s', $list_id, $sheet_name)
            );

            // Verify data structure
            $values = $response->values ?? [];

            if (empty($values)) return false; // No data found in the sheet

            $matches = [];

            // Find the row with the matching email
            for ($i = 1; $i < count($values); $i++) {
                if (isset($values[$i][$email_column_index])) {
                    $sheet_email = strtolower(trim($values[$i][$email_column_index]));
                    $input_email = strtolower(trim($email_address));

                    if ($sheet_email === '') continue;

                    if ($sheet_email === $input_email) {
                        $matches[] = [
                            'row_index'          => $i + 1, // 1-indexed for Google Sheets
                            'sheet_name'         => $sheet_name,
                            'email_column_index' => $email_column_index,
                            'headers'            => $headers,
                            'row_data'           => $values[$i],
                        ];
                    }
                }
            }

            return $matches;

        } catch (\Exception $e) {
            return false;
        }
    }
}
