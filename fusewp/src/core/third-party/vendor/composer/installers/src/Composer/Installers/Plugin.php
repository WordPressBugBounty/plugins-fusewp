<?php

namespace FuseWPVendor\Composer\Installers;

use FuseWPVendor\Composer\Composer;
use FuseWPVendor\Composer\IO\IOInterface;
use FuseWPVendor\Composer\Plugin\PluginInterface;
class Plugin implements PluginInterface
{
    /** @var Installer */
    private $installer;
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->installer);
    }
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $composer->getInstallationManager()->removeInstaller($this->installer);
    }
    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }
}
