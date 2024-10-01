<?php

namespace FuseWP\Core\Integrations\HubSpot;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $hubspotInstance;

    /**
     * @param HubSpot $hubspotInstance
     */
    public function __construct($hubspotInstance)
    {
        parent::__construct($hubspotInstance);

        $this->hubspotInstance = $hubspotInstance;
    }
}