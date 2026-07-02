<?php

namespace FuseWP\Core\Integrations\Copper;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $copperInstance;

    /**
     * @param Copper $copperInstance
     */
    public function __construct($copperInstance)
    {
        parent::__construct($copperInstance);

        $this->copperInstance = $copperInstance;
    }
}