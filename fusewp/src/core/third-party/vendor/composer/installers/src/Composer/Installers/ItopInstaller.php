<?php

namespace FuseWPVendor\Composer\Installers;

class ItopInstaller extends BaseInstaller
{
    protected $locations = array('extension' => 'extensions/{$name}/');
}
