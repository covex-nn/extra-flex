<?php

namespace Covex\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
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

        $recipe = $this->createRecipe($package, $operation->getJobType());
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
     * @param PackageInterface $package
     * @param string           $job
     *
     * @return Recipe|null
     */
    protected function createRecipe(PackageInterface $package, $job)
    {
        $extra = $package->getExtra();
        if (!isset($extra["recipe-dir"])) {
            return null;
        }

        $name = $package->getPrettyName();
        $version = $package->getPrettyVersion();

        $path = $this->composer->getInstallationManager()->getInstallPath($package) . "/" .
            trim($extra["recipe-dir"], "\\/");
        $path = str_replace("\\", "/", $path);

        $manifestPath = $path . "/manifest.json";

        $json = new JsonFile($manifestPath);
        if (!$json->exists()) {
            return null;
        }
        $manifest = $json->read();
        if (!is_array($manifest)) {
            return null;
        }
        $files = array();
        if (isset($manifest["copy-from-recipe"]) && is_array($manifest["copy-from-recipe"])) {
            foreach ($manifest["copy-from-recipe"] as $source => $destination) {
                $directory = $path . "/" . trim($source, "\\/");
                if (!file_exists($directory)) {
                    continue;
                }
                if (is_dir($directory)) {
                    $it = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
                    $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

                    foreach ($ri as $file) {
                        /** @var \SplFileInfo $file */
                        if ($file->isFile()) {
                            $filename = $file->getRealPath();

                            $key = str_replace($path . "/", "", str_replace("\\", "/", $file));
                            $files[$key] = [
                                "contents" => file_get_contents($filename),
                                "executable" => false,
                            ];
                        }
                    }
                } elseif (is_file($directory)) {
                    $files[$source] = [
                        "contents" => file_get_contents($path . "/" . $source),
                        "executable" => false
                    ];
                }
            }
        }

        return new Recipe($package, $name, $job, [
            "repository" => "vendor", // ???
            "package" => $name,
            "version" => $version,
            "manifest" => $manifest,
            "files" => $files,
            "origin" => sprintf('%s:%s@self-contain recipe', $name, $version),
            "not_installable" => false,
            "is_contrib" => false,
        ]);
    }
}
