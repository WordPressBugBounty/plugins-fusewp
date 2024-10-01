<?php

namespace FuseWP\Core\Integrations\Aweber;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $aweberInstance;

    /**
     * @param Aweber $aweberInstance
     */
    public function __construct($aweberInstance)
    {
        parent::__construct($aweberInstance);

        $this->aweberInstance = $aweberInstance;

        add_action('fusewp_after_save_oauth_credentials', [$this, 'save_account_id']);
    }

    public function save_account_id()
    {
        try {
            $account_id = $this->aweberInstance->apiClass()->fetchAccountId();
        } catch (\Exception $e) {
            $account_id = '';
        }

        $old_data                                          = get_option(FUSEWP_SETTINGS_DB_OPTION_NAME, []);
        $old_data[$this->aweberInstance->id]['account_id'] = $account_id;
        update_option(FUSEWP_SETTINGS_DB_OPTION_NAME, $old_data);
    }
}