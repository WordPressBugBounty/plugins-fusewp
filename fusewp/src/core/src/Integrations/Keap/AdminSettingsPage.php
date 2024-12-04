<?php

namespace FuseWP\Core\Integrations\Keap;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $keapInstance;

    /**
     * @param Keap $keapInstance
     */
    public function __construct($keapInstance)
    {
        parent::__construct($keapInstance);

        $this->keapInstance = $keapInstance;
    }
}