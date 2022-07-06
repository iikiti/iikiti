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

        //$loader = require BASE_DIR . DS . 'vendor' . DS . 'autoload.php';

        $extAry = $site->getEnabledExtensions();

        spl_autoload_register([self::class, 'autoloadClass'], true, false);

        foreach($extAry as $ext) {
            $prefix = self::PREFIX .
                str_replace('/', '\\', dirname($ext)) . '\\';
            $path = BASE_DIR . DS . 'cms' . DS . 'extensions' . DS .
                dirname($ext) . DS;
            dump($prefix, $path);
            //$loader->addPsr4($prefix,$path);
            //$loader->register();
            $extClass = str_replace('\\', '/', self::PREFIX) . $ext;
            dump($extClass);
            dump(new $extClass());
        }

        spl_autoload_unregister([self::class, 'autoloadClass']);

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

    protected static function _loadExtensionPsr4(
        ClassLoader $loader,
        SplFileInfo $entry
    ){
        $extName = basename($entry->getPathname());
        $vendor = basename(dirname($entry->getPathname()));
        $extPrefix = self::PREFIX . $vendor . '\\' . $extName . '\\';
        $extPath = $entry->getPathname() . DS;
        $loader->addPsr4($extPrefix, $extPath);
        var_dump($extPrefix);
        var_dump($extPath);
    }

    public static function autoloadClass($class) {
        dump($class);
    }

}