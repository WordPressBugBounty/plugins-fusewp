<?php

namespace FuseWPVendor\Composer\Installers;

class WordPressInstaller extends BaseInstaller
{
    protected $locations = array('plugin' => 'wp-content/plugins/{$name}/', 'theme' => 'wp-content/themes/{$name}/', 'muplugin' => 'wp-content/mu-plugins/{$name}/', 'dropin' => 'wp-content/{$name}/');
}
