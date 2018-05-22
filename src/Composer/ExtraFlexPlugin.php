<?php

namespace Covex\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Covex\Composer\Command\CommandProvider;
use Symfony\Flex\Configurator;
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
     * @var Lock
     */
    private $lock;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var Recipe[]
     */
    private $recipes = [];

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer     = $composer;
        $this->io           = $io;
        $this->configurator = new Configurator($composer, $io, self::getOptions($composer));

        if (!$this->lock) {
            $this->lock = new Lock(str_replace(Factory::getComposerFile(), 'composer.json', 'extra-flex.lock'));
        }
    }

    /**
     * @param Composer $composer
     *
     * @return Options
     */
    private static function getOptions(Composer $composer): Options
    {
        $options = array_merge(
            [
                'bin-dir'    => 'bin',
                'conf-dir'   => 'conf',
                'config-dir' => 'config',
                'src-dir'    => 'src',
                'var-dir'    => 'var',
                'public-dir' => 'public',
            ],
            $composer->getPackage()->getExtra()
        );

        return new Options($options);
    }

    /**
     * {@inheritdoc}
     */
    public function getCapabilities()
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL  => [
                ['record'],
            ],
            PackageEvents::PRE_PACKAGE_UNINSTALL => [
                ['record'],
            ],
            ScriptEvents::POST_INSTALL_CMD       => [
                ['install', 42 /* ensure embedded recipes are applied before public recipes */],
            ],
            ScriptEvents::POST_UPDATE_CMD        => [
                ['update', 42 /* ensure embedded recipes are applied before public recipes */],
            ],
        ];
    }

    /**
     * @param PackageEvent $event
     */
    public function record(PackageEvent $event)
    {
        $operation = $event->getOperation();

        if ($operation instanceof InstallOperation || $operation instanceof UninstallOperation) {
            $recipe = $this->getRecipe($operation);

            if ($recipe instanceof Recipe) {
                $this->recipes[] = $recipe;
            }
        }
    }

    /**
     * @param Event $event
     */
    public function install(Event $event)
    {
        $this->update($event);
    }

    /**
     * @param Event $event
     */
    public function update(Event $event)
    {
        if (empty($this->recipes)) {
            return;
        }

        $this->io->writeError(
            sprintf(
                '<info>Extra flex operations: %d recipe%s</>',
                count($this->recipes),
                count($this->recipes) > 1 ? 's' : ''
            )
        );

        foreach ($this->recipes as $recipe) {
            $this->applyRecipe($recipe);
        }

        $this->lock->write();
    }

    /**
     * @param InstallOperation|UninstallOperation|OperationInterface $operation
     *
     * @return Recipe|null
     */
    private function getRecipe(OperationInterface $operation)
    {
        $package = $operation->getPackage();
        $name    = $package->getPrettyName();
        $recipe  = $this->recipeFromInstalledPackage($package, $operation->getJobType());

        if ($recipe instanceof Recipe) {
            if ($operation instanceof InstallOperation && !$this->lock->has($name)) {
                return $recipe;
            } elseif ($operation instanceof UninstallOperation && $this->lock->has($name)) {
                return $recipe;
            }
        }

        return null;
    }

    /**
     * @param PackageInterface $package Package
     * @param string           $job     Job type (install/uninstall)
     *
     * @return Recipe|null
     */
    private function recipeFromInstalledPackage(PackageInterface $package, string $job)
    {
        $extra = $package->getExtra();
        if (!isset($extra[RecipeHelper::EXTRA_RECIPE_DIR])) {
            return null;
        }
        $recipePath = $this->composer->getInstallationManager()
                ->getInstallPath($package) . '/' . trim($extra[RecipeHelper::EXTRA_RECIPE_DIR], '\/');

        return RecipeHelper::createFromPath($package, $recipePath, $job);
    }

    /**
     * Install or uninstall recipe.
     *
     * @param Recipe $recipe
     */
    public function applyRecipe(Recipe $recipe)
    {
        $job     = $recipe->getJob();
        $package = $recipe->getPackage();
        if ('install' === $job) {
            $this->io->writeError(sprintf('  - Configuring %s', $this->formatOrigin($recipe->getOrigin())));
            $this->configurator->install($recipe);
            $this->lock->add($package->getPrettyName(), $package->getPrettyVersion());
        } elseif ('uninstall' === $job) {
            $this->io->writeError(sprintf('  - Unconfiguring %s', $this->formatOrigin($recipe->getOrigin())));
            $this->configurator->unconfigure($recipe);
            $this->lock->remove($package->getPrettyName());
        }
    }

    /**
     * Format a recipe's origin, copied from the flex plugin to achieve the same look and feel.
     *
     * @param string $origin
     *
     * @return string
     */
    private function formatOrigin(string $origin): string
    {
        // symfony/translation:3.3@github.com/symfony/recipes:master
        if (!preg_match('/^([^\:]+?)\:([^\@]+)@(.+)$/', $origin, $matches)) {
            return $origin;
        }

        return sprintf('<info>%s</> (<comment>>=%s</>): From %s', $matches[1], $matches[2], $matches[3]);
    }
}
