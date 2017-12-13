<?php

namespace Covex\Composer\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Andrey Mindubaev <andrey@mindubaev.ru>
 */
class ApplyCommand extends BaseCommand
{
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
                    'packages',
                    InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                    'Packages that should be downloaded and applied as recipe'
                )
           ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Applying recipes");

        $output->writeln(var_export($input->getArgument("packages")));
    }
}
