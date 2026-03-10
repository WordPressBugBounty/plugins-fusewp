<?php

namespace FuseWP\Core\Integrations\Birdsend;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $birdsendInstance;

    /**
     * @param Birdsend $birdsendInstance
     */
    public function __construct($birdsendInstance)
    {
        parent::__construct($birdsendInstance);

        $this->birdsendInstance = $birdsendInstance;
    }
}
