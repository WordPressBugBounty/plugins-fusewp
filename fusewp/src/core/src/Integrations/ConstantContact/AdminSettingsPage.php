<?php

namespace FuseWP\Core\Integrations\ConstantContact;

use FuseWP\Core\Integrations\AbstractOauthAdminSettingsPage;

class AdminSettingsPage extends AbstractOauthAdminSettingsPage
{
    protected $constantcontactInstance;

    /**
     * @param ConstantContact $constantcontactInstance
     */
    public function __construct($constantcontactInstance)
    {
        parent::__construct($constantcontactInstance);

        $this->constantcontactInstance = $constantcontactInstance;
    }
}