<?php

namespace iikiti\CMS\Loader;

use Composer\Autoload\ClassLoader;
use GlobIterator;
use iikiti\CMS\Registry\SiteRegistry;
use SplFileInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class Extensions {

    const PREFIX = 'iikiti\\extension\\';

    public static function load(ContainerInterface $container) {
        $site = SiteRegistry::getCurrentSite();

        $extAry = $site->getEnabledExtensions();

        foreach($extAry as $ext) {
            $prefix = self::PREFIX .
                str_replace('/', '\\', dirname($ext)) . '\\';
            $path = BASE_DIR . DS . 'cms' . DS . 'extensions' . DS .
                dirname($ext) . DS;
            $extClass = self::PREFIX . str_replace('/', '\\', $ext);
            if(is_dir($path) && file_exists($path)) {
                self::_loadExtensionPsr4(new SplFileInfo($path));
            }
        }

    }

    public static function registerAll(ClassLoader $loader)
    {
        foreach(self::_loadExtensionClasses($loader) as $entry) {
            self::_loadExtensionPsr4($loader, $entry);
        }
    }

    protected static function _loadExtensionClasses()
    {
        static $GLOB = BASE_DIR . DS . 'cms' . DS . 'extensions' .
            DS . '*' . DS . '*';
        foreach(new GlobIterator($GLOB) as $entry) {
            // Must be a directory or symbolic link
            if(
                !($entry->isDir() ||
                $entry->isLink()) ||
                !($entry instanceof SplFileInfo)
            ) {
                continue;
            }
            yield $entry;
        }
    }

    protected static function _loadExtensionPsr4(SplFileInfo $path): void {
        static $loader = PSR4LOADER;
        $extName = basename($path->getPathname());
        $vendor = basename($path->getPath());
        $extPrefix = self::PREFIX . $vendor . '\\' . $extName . '\\';
        $extPath = $path->getPathname() . DS;
        //$class = $extPrefix . 'ComponentsBundle';

        $composerFile = $extPath . 'composer.json';

        if(!file_exists($composerFile)) {
            $loader->addPsr4($extPrefix, $extPath);
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
            $loader->addPsr4($psr4_prefix, $psr4_path);
        }
    }

}