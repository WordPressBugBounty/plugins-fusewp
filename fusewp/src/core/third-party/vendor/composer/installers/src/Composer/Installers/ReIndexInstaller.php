<?php

namespace FuseWPVendor\Composer\Installers;

class ReIndexInstaller extends BaseInstaller
{
    protected $locations = array('theme' => 'themes/{$name}/', 'plugin' => 'plugins/{$name}/');
}
