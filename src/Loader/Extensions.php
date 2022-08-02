<?php

namespace iikiti\CMS\Loader;

use iikiti\CMS\Registry\SiteRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class Extensions {

    const PREFIX = 'iikiti\\extension\\';

    public static function load(ContainerInterface $container) {
        $site = SiteRegistry::getCurrentSite();

        $extAry = $site->getEnabledExtensions();

        foreach($extAry as $ext) {
            /* TODO: Needed?
             * $prefix = self::PREFIX .
             *   str_replace('/', '\\', dirname($ext)) . '\\';
             * $extClass = self::PREFIX . str_replace('/', '\\', $ext);
             */
            $path = BASE_DIR . DS . 'cms' . DS . 'extensions' . DS .
                dirname($ext) . DS;
            // TODO: Check for cms extension instance
            if(is_dir($path) && file_exists($path)) {
                new (self::_loadExtensionPsr4(new \SplFileInfo($path)))();
            }
        }
    }

    protected static function _loadExtensionPsr4(\SplFileInfo $path): string {
        $extName = basename($path->getPathname());
        $vendor = basename($path->getPath());
        $extPrefix = self::PREFIX . $vendor . '\\' . $extName . '\\';
        $extPath = $path->getPathname() . DS;
        PSR4LOADER->addPsr4($extPrefix, $extPath);
        self::_loadExtensionPsr4_composer($extPrefix, $extPath);
        return $extPrefix . ucfirst($extName) . 'Extension';
    }

    protected static function _loadExtensionPsr4_composer(
        string $extPrefix,
        string $extPath
    ): void {
        $composerFile = $extPath . 'composer.json';

        if(!file_exists($composerFile)) {
            return;
        }

        $composer = json_decode(file_get_contents($composerFile));

        if(!isset($composer->autoload->{'psr-4'})) {
            return;
        }

        foreach($composer->autoload->{'psr-4'} as $psr4_prefix => $psr4_path) {
            if(!str_starts_with($psr4_prefix, $extPrefix)) {
                $psr4_prefix = $extPrefix . $psr4_prefix;
            }
            $psr4_path = $extPath . $psr4_path;
            PSR4LOADER->addPsr4($psr4_prefix, $psr4_path);
        }
    }

}