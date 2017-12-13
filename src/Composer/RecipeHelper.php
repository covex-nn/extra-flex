<?php

namespace Covex\Composer;

use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Symfony\Flex\Recipe;

/**
 * @author Andrey Mindubaev <andrey@mindubaev.ru>
 */
class RecipeHelper
{
    const EXTRA_RECIPE_DIR = "recipe-dir";

    /**
     * @param PackageInterface $package
     * @param string           $path
     * @param string           $job
     *
     * @return null|Recipe
     */
    public static function createFromPath(PackageInterface $package, $path, $job)
    {
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

        $name = $package->getPrettyName();
        $version = $package->getPrettyVersion();

        return new Recipe($package, $name, $job, [
            "repository" => "extra-flex/" . $name, // fixme
            "package" => $name,
            "version" => $version,
            "manifest" => $manifest,
            "files" => $files,
            "origin" => sprintf('%s:%s@self-containing recipe', $name, $version),
            "not_installable" => false,
            "is_contrib" => false, // fixme
        ]);
    }
}
