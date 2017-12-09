<?php

namespace Covex\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Flex\Configurator;
use Symfony\Flex\Flex;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

class ExtraFlex implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var Options
     */
    private $options;

    /**
     * @var Lock
     */
    private $lock;

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        if (!is_null($this->composer) || !is_null($this->io)) {
            return;
        }
        $this->composer = $composer;
        $this->io = $io;

        $flex = null;
        foreach ($composer->getPluginManager()->getPlugins() as $plugin) {
            if ($plugin instanceof Flex) {
                $flex = $plugin;
                break;
            }
        }

        list($this->options, $this->lock) = \Closure::bind(function (Composer $composer, IOInterface $io) {
            if (isset($this)) {
                $flex = $this;
            } else {
                $flex = new Flex();
                $flex->activate($composer, $io);
            }

            return [ $flex->initOptions(), $flex->lock ];
        }, null, Flex::class)->__invoke($this->composer, $this->io);

        $this->configurator = new Configurator($composer, $io, $this->options);

        $io->writeError("ExtraFlex is activated");
    }

    /**
     * Record events
     *
     * @param PackageEvent $event Package event
     */
    public function record(PackageEvent $event)
    {
        $operation = $event->getOperation();
        /** @var InstallOperation|UninstallOperation|UpdateOperation $operation */
        if ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            $package = $operation->getPackage();
        }
        $shouldRecord = false;
        $name = $package->getName();
        /** @var PackageInterface $package */
        if ($operation instanceof InstallOperation) {
            if (!$this->lock->has($name)) {
                $shouldRecord = true;
            }
        } elseif ($operation instanceof UninstallOperation) {
            $shouldRecord = true;
        }
        if ($shouldRecord) {
            $this->io->writeError("[ExtraFlex::record] " . $operation->getJobType() . " " . $name);
        }
    }

    public function install(Event $event)
    {
        $this->io->writeError("[ExtraFlex::install]");
    }

    public function update(Event $event)
    {
        $this->io->writeError("[ExtraFlex::update]");
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'record',
            PackageEvents::POST_PACKAGE_UPDATE => 'record',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'record',
            ScriptEvents::POST_INSTALL_CMD => 'install',
            ScriptEvents::POST_UPDATE_CMD => 'update',
        ];
    }
}
