<?php

namespace FuseWPVendor\Composer\Installers;

class KnownInstaller extends BaseInstaller
{
    protected $locations = array('plugin' => 'IdnoPlugins/{$name}/', 'theme' => 'Themes/{$name}/', 'console' => 'ConsolePlugins/{$name}/');
}
