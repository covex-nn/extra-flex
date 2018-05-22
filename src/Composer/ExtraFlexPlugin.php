<?php

namespace Covex\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Symfony\Flex\Configurator;
use Symfony\Flex\Downloader;
use Symfony\Flex\Flex;
use Symfony\Flex\Lock;
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
     * @var Configurator|Proxy
     */
    private $configurator;

    /**
     * @var Downloader|Proxy
     */
    private $downloader;

    /**
     * @var Lock
     */
    private $lock;

    /**
     * @var Flex|Proxy
     */
    private $flex;

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
        $this->composer = $composer;
        $this->io = $io;

        if (!$this->lock) {
            $this->lock = new Lock(str_replace(Factory::getComposerFile(), 'composer.json', 'extra-flex.lock'));
        }
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
     * Decorate flex
     */
    public function decorate()
    {
        if (is_null($this->flex)) {
            $flex = null;
            foreach ($this->composer->getPluginManager()->getPlugins() as $plugin) {
                if ($plugin instanceof Flex || strpos(get_class($plugin), Flex::class . '_composer_tmp') === 0) {
                    $flex = $plugin;
                    break;
                }
            }
            if (is_null($flex)) {
                throw new \RuntimeException('Flex should be installed! Cannot work without Flex plugin =(');
            }
            $this->flex = new Proxy($flex);
        }

        $properties = [
            'configurator' => ['install', 'unconfigure'],
            'downloader' => ['getRecipes']
        ];

        foreach ($properties as $name => $methods) {
            if (is_null($this->{$name})) {
                $object = $this->flex->{$name};
                $proxy = $object instanceof Proxy ? $object : new Proxy($object);

                if (sizeof($methods)) {
                    $proxy->setEventDispatcher($this->composer->getEventDispatcher());
                    $proxy->subscribe($name, $methods);
                }

                $this->{$name} = $proxy;
            }
            if (!$this->flex->{$name} instanceof Proxy) {
                $this->flex->{$name} = $this->{$name};
            }
        }
    }

    /**
     * Configurator Log
     *
     * @param Event $event
     */
    public function configuratorLog(Event $event)
    {
        if ($this->io->isVerbose()) {
            $recipe = $event->getArguments()["arguments"][0];
            /** @var Recipe $recipe */
            $this->io->write('');
            $this->io->write(
                '    Flex/' . $event->getName() . " " . $recipe->getName()
            );
        }
    }

    /**
     * Downloader Log
     *
     * @param Event $event
     */
    public function downloaderLog(Event $event)
    {
        if ($this->io->isVerbose()) {
            $this->io->write(
                '    Flex/' . $event->getName()
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::INIT => [
                ['decorate', 42],
            ],
            PackageEvents::PRE_PACKAGE_INSTALL => [
                ['decorate', 42],
            ],
            PackageEvents::POST_PACKAGE_INSTALL => [
                ['decorate', 42],
                ['update'],
            ],
            PackageEvents::PRE_PACKAGE_UNINSTALL => [
                ['decorate', 42],
                ['update'],
            ],
            'pre-flex-configurator-install' => 'configuratorLog',
            'post-flex-configurator-install' => 'configuratorLog',
            'pre-flex-configurator-unconfigure' => 'configuratorLog',
            'post-flex-configurator-unconfigure' => 'configuratorLog',
            'pre-flex-downloader-getRecipes' => 'downloaderLog',
            'post-flex-downloader-getRecipes' => 'downloaderLog',
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
            ->getInstallPath($package) . '/' . trim($extra[RecipeHelper::EXTRA_RECIPE_DIR], '\/');

        return RecipeHelper::createFromPath($package, $recipePath, $job);
    }
}
