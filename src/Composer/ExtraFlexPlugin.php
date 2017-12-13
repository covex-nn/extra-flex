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
        if (!$flex) {
            $flex = new Flex();
            $flex->activate($composer, $io);
        }

        $ref = new \ReflectionClass(Flex::class);
        $method = $ref->getMethod("initOptions");
        $method->setAccessible(true);
        $this->options = $method->invoke($flex);

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

        $recipe = $this->recipeFromInstalledPackage($package, $operation->getJobType());
        if ($recipe instanceof Recipe) {
            if ($operation instanceof InstallOperation && !$this->lock->has($name)) {
                $this->applyRecipe($recipe);
            } elseif ($operation instanceof UninstallOperation && $this->lock->has($name)) {
                $this->applyRecipe($recipe);
            }
        }
    }

    /**
     * Install recipe
     *
     * @param Recipe $recipe
     * @param bool   $updateLock
     */
    public function applyRecipe(Recipe $recipe, $updateLock = true)
    {
        $job = $recipe->getJob();
        $package = $recipe->getPackage();
        if ('install' === $job) {
            $this->configurator->install($recipe);
            $this->lock->add($package->getPrettyName(), $package->getPrettyVersion());
        } elseif ('uninstall' === $job) {
            $this->configurator->unconfigure($recipe);
            $this->lock->remove($package->getPrettyName());
        }
        if ($updateLock) {
            $this->lock->write();
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'update',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'update',
        ];
    }

    /**
     * @param PackageInterface $package Package
     * @param string           $job     Job type (install/uninstall)
     *
     * @return Recipe|null
     */
    protected function recipeFromInstalledPackage(PackageInterface $package, $job)
    {
        $extra = $package->getExtra();
        if (!isset($extra[RecipeHelper::EXTRA_RECIPE_DIR])) {
            return null;
        }
        $recipePath = $this->composer->getInstallationManager()
            ->getInstallPath($package) . "/" . trim($extra[RecipeHelper::EXTRA_RECIPE_DIR], "\\/");

        return RecipeHelper::createFromPath($package, $recipePath, $job);
    }
}
