<?php

namespace FuseWPVendor\Composer\Installers;

class WolfCMSInstaller extends BaseInstaller
{
    protected $locations = array('plugin' => 'wolf/plugins/{$name}/');
}
