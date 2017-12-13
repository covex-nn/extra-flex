<?php

namespace Covex\Composer\Command;

use Composer\Composer;
use Composer\IO\ConsoleIO;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Covex\Composer\ExtraFlexDebug;

/**
 * @author Andrey Mindubaev <andrey@mindubaev.ru>
 */
class CommandProvider implements CommandProviderCapability
{
    public function __construct($args)
    {
        $composer = $args["composer"];
        /** @var $composer Composer */
        $io = $args["io"];
        /** @var $io ConsoleIO */
        $plugin = $args["plugin"];
        /** @var $plugin ExtraFlexDebug */
    }

    /**
     * {@inheritdoc}
     */
    public function getCommands()
    {
        $apply = new ApplyCommand();

        return [
            $apply
        ];
    }
}
