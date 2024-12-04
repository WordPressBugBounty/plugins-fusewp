<?php

namespace FuseWP\Core\Integrations\ZohoCampaigns;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $zohocampaignsInstance;

    /**
     * @param ZohoCampaigns $zohocrmInstance
     */
    public function __construct($zohocampaignsInstance)
    {
        parent::__construct($zohocampaignsInstance);

        $this->zohocampaignsInstance = $zohocampaignsInstance;
    }
}