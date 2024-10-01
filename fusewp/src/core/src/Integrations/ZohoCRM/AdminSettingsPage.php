<?php

namespace FuseWP\Core\Integrations\ZohoCRM;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $zohocrmInstance;

    /**
     * @param ZohoCRM $zohocrmInstance
     */
    public function __construct($zohocrmInstance)
    {
        parent::__construct($zohocrmInstance);

        $this->zohocrmInstance = $zohocrmInstance;
    }
}