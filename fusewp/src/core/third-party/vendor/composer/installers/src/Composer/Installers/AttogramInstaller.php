<?php

namespace FuseWPVendor\Composer\Installers;

class AttogramInstaller extends BaseInstaller
{
    protected $locations = array('module' => 'modules/{$name}/');
}
