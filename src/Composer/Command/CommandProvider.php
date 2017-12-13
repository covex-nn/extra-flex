<?php

namespace Covex\Composer\Command;

use Composer\Composer;
use Composer\IO\ConsoleIO;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Covex\Composer\ExtraFlexPlugin;

/**
 * @author Andrey Mindubaev <andrey@mindubaev.ru>
 */
class CommandProvider implements CommandProviderCapability
{
    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var ConsoleIO
     */
    private $io;

    /**
     * @var ExtraFlexPlugin
     */
    private $plugin;

    public function __construct($args)
    {
        $this->composer = $args["composer"];
        $this->io = $args["io"];
        $this->plugin = $args["plugin"];
    }

    /**
     * {@inheritdoc}
     */
    public function getCommands()
    {
        $apply = new ApplyCommand();
        $apply->setComposer($this->composer);
        $apply->setIO($this->io);
        $apply->setPlugin($this->plugin);

        return [ $apply ];
    }
}
