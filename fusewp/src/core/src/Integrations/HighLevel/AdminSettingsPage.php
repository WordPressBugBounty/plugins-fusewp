<?php

namespace FuseWP\Core\Integrations\HighLevel;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $highlevelInstance;

    /**
     * @param HighLevel $highlevelInstance
     */
    public function __construct($highlevelInstance)
    {
        parent::__construct($highlevelInstance);

        $this->highlevelInstance = $highlevelInstance;
    }
}