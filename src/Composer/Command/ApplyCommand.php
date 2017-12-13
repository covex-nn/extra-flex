<?php

namespace Covex\Composer\Command;

use Composer\Command\BaseCommand;
use Composer\Util\Filesystem;
use Covex\Composer\ExtraFlexPlugin;
use Covex\Composer\RecipeHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Flex\Recipe;

/**
 * @author Andrey Mindubaev <andrey@mindubaev.ru>
 */
class ApplyCommand extends BaseCommand
{
    /**
     * @var ExtraFlexPlugin
     */
    private $plugin;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('apply')
            ->setDescription('Apply recipe from package')
            ->setDefinition([
                new InputArgument(
                    'package',
                    InputArgument::REQUIRED,
                    'Package that should be downloaded and applied as recipe'
                ),
                new InputArgument(
                    'version',
                    InputArgument::REQUIRED,
                    'Package version',
                    '*'
                ),
           ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $package = $input->getArgument("package");
        $version = $input->getArgument("version");

        $prettyName = $package . ("" === $version ? "" : ":$version");

        $composer = $this->getComposer();
        $package = $composer->getRepositoryManager()
            ->findPackage($package, $version);

        if (!$package) {
            $output->writeln("Package $prettyName was not found!");
        } else {
            $tmpDir = sys_get_temp_dir() . "/" . uniqid("extra-flex");
            $tmpDir = getcwd() . "/qwerty";

            $fs = new Filesystem();
            $fs->emptyDirectory($tmpDir);

            $downloader = $composer->getDownloadManager();
            $downloader->download($package, $tmpDir, false);

            $extra = $package->getExtra();
            if (isset($extra[RecipeHelper::EXTRA_RECIPE_DIR])) {
                $recipePath = $tmpDir . "/" . trim($extra[RecipeHelper::EXTRA_RECIPE_DIR], "\\/");

                $recipe = RecipeHelper::createFromPath($package, $recipePath, "install");
                if ($recipe instanceof Recipe) {
                    $output->writeln("  - Applying recipe");
                    $this->plugin->applyRecipe($recipe);
                } else {
                    $output->writeln("  - Recipe is not valid");
                }
            } else {
                $output->writeln("Recipe was not embedded into $prettyName");
            }
            $fs->removeDirectory($tmpDir);
        }
    }

    /**
     * @param ExtraFlexPlugin $plugin Extra-Flex plugin
     */
    public function setPlugin(ExtraFlexPlugin $plugin)
    {
        $this->plugin = $plugin;
    }
}
