<?php

namespace Covex\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Symfony\Flex\Configurator;
use Symfony\Flex\Flex;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;

/**
 * @author Andrey F. Mindubaev <andrey@mindubaev.ru>
 */
class ExtraFlexPlugin implements Capable, PluginInterface, EventSubscriberInterface
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
    public function getCapabilities()
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'Covex\Composer\Command\CommandProvider',
        ];
    }

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
        $this->options = \Closure::bind(function (Composer $composer, IOInterface $io) {
            if (isset($this)) {
                $flex = $this;
            } else {
                $flex = new Flex();
                $flex->activate($composer, $io);
            }

            return $flex->initOptions();
        }, $flex, Flex::class)->__invoke($this->composer, $this->io);

        $this->lock = new Lock(str_replace(Factory::getComposerFile(), 'composer.json', 'extra-flex.lock'));
        $this->configurator = new Configurator($composer, $io, $this->options);
    }

    public function update(PackageEvent $event)
    {
        $operation = $event->getOperation();
        /** @var OperationInterface $operation */
        if ($operation instanceof InstallOperation || $operation instanceof UninstallOperation) {
            $package = $operation->getPackage();
        } else {
            return;
        }
        $name = $package->getPrettyName();

        $recipe = $this->recipeFromPackage($package, $operation->getJobType());
        if ($recipe instanceof Recipe) {
            if ($operation instanceof InstallOperation && !$this->lock->has($name)) {
                $this->configurator->install($recipe);

                $this->lock->add($name, $package->getPrettyVersion());
                $this->lock->write();
            } elseif ($operation instanceof UninstallOperation && $this->lock->has($name)) {
                $this->configurator->unconfigure($recipe);

                $this->lock->remove($name);
                $this->lock->write();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'update',
            // PackageEvents::POST_PACKAGE_UPDATE => 'update',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'update',
        ];
    }

    /**
     * @param PackageInterface $package Package
     * @param string           $job     Job type (install/uninstall)
     *
     * @return Recipe|null
     */
    protected function recipeFromPackage(PackageInterface $package, $job)
    {
        $extra = $package->getExtra();
        if (!isset($extra["recipe-dir"])) {
            return null;
        }
        $recipePath = $this->composer->getInstallationManager()->getInstallPath($package) . "/" . trim($extra["recipe-dir"], "\\/");

        return RecipeHelper::createFromPath($package, $recipePath, $job);
    }
}
