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

    protected static function _loadExtensionPsr4(SplFileInfo $path): void {
        static $loader = PSR4LOADER;
        $extName = basename($path->getPathname());
        $vendor = basename($path->getPath());
        $extPrefix = self::PREFIX . $vendor . '\\' . $extName . '\\';
        $extPath = $path->getPathname() . DS;
        $class = $extPrefix . ucfirst($extName) . 'Bundle';

        $composerFile = $extPath . 'composer.json';

        $loader->addPsr4($extPrefix, $extPath);

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
            $loader->addPsr4($psr4_prefix, $psr4_path);
        }

    }

}