<?php

namespace iikiti\CMS\Loader;

use iikiti\CMS\Kernel;
use iikiti\CMS\Registry\SiteRegistry;

abstract class Extensions {

    const PREFIX = 'iikiti\\extension\\';
    const INITIAL_KEY = 'initial';
    
    static array $EXTENSIONS = [];
    
    /**
     * Acquires list of enabled extensions and loads them via PSR-4 then
     * creates a new instance.
     *
     */
    public static function load(Kernel $kernel): \Generator {
        $gen = self::_loadFromDirectory($kernel);
        $cur = $gen->current();
        if($cur !== null) {
            self::$EXTENSIONS[
                SiteRegistry::getCurrentSite()->getId() ?? self::INITIAL_KEY
            ][] = $gen->current();
        }
        yield from $cur !== null ? $gen : [];
    }

    public static function setInitialSiteId(int|string $siteId): void {
        self::$EXTENSIONS[$siteId] = self::$EXTENSIONS[self::INITIAL_KEY] ?? [];
        unset(self::$EXTENSIONS[self::INITIAL_KEY]);
    }

    protected static function _loadFromDirectory(Kernel $kernel): \Generator {
        $PATH = $kernel->getProjectDir() . '/cms/extensions/active/';
        $fileLoop = new \CallbackFilterIterator(
            new \DirectoryIterator($PATH),
            [__CLASS__, '_directoryFilter']
        );
        foreach($fileLoop as $entry) {
            /** @var \DirectoryIterator $entry */
            $bundleNS = (string) json_decode(file_get_contents(
                $entry->getPath() . '/' . $entry->getFilename() .
                '/composer.json'
            ))->name;
            $bundleName = ucwords(
                implode('', array_slice(explode('/', $bundleNS), -1))
            );
            /** @psalm-suppress UnresolvableInclude */
            require_once(
                $entry->getPath() . '/' . $entry->getFilename() . '/' . 
                $bundleName . 'Bundle.php'
            );
            $bundleClass = str_replace('/', '\\', $bundleNS) . '\\' .
                $bundleName . 'Bundle';
            /*
             TODO Find a better way to get extension list
                  (or any required arguments) to the extension bundles.
             */
            yield new $bundleClass(self::getExtensions());
        }
    }

    protected static function _directoryFilter(
        \DirectoryIterator $current,
        int $key,
        \Iterator $iterator
    ): bool {
        return false === $current->isDot() && $current->isDir() && $current->isLink();
    }

    public static function getExtensions(): array {
        return self::$EXTENSIONS[
            SiteRegistry::getCurrentSite()->getId() ?? self::INITIAL_KEY
        ] ?? []; 
    }

}