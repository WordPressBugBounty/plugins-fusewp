<?php

namespace FuseWPVendor\Composer\Installers;

class PPIInstaller extends BaseInstaller
{
    protected $locations = array('module' => 'modules/{$name}/');
}
