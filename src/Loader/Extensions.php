<?php

namespace iikiti\CMS\Loader;

use iikiti\ExtensionUtilities\ExtensionInterface;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use iikiti\CMS\BASE_DIR;

abstract class Extensions {

    const PREFIX = 'iikiti\\extension\\';
    
    static $EXTENSIONS = [];
    
    /**
     * Acquires list of enabled extensions and loads them via PSR-4 then
     * creates a new instance.
     *
     * @return void
     */
    public static function load(): \Generator {
        $gen = self::_loadFromDirectory();
        self::$EXTENSIONS[] = $gen->current();
        yield from $gen;
    }

    protected static function _loadFromDirectory(): \Generator {
        static $PATH = BASE_DIR . DS . 'cms' . DS . 'extensions' . DS .
            'active' . DS;
        foreach(
            new \CallbackFilterIterator(
                new \DirectoryIterator($PATH),
                [__CLASS__, '_directoryFilter']
            )
                as $entry
        ) {
            /** @var \DirectoryIterator $entry */
            $bundleNS = json_decode(file_get_contents(
                $entry->getPath() . DS . $entry->getFilename() .
                DS . 'composer.json'
            ))->name;
            $bundleName = ucwords(
                implode('', array_slice(explode('/', $bundleNS), -1))
            );
            require_once(
                $entry->getPath() . DS . $entry->getFilename() . DS . 
                $bundleName . 'Bundle.php'
            );
            $bundleClass = str_replace('/', '\\', $bundleNS) . '\\' .
                $bundleName . 'Bundle';
            yield new $bundleClass();
        }
    }

    protected static function _directoryFilter(
        \DirectoryIterator $current,
        int $key,
        \Iterator $iterator
    ): bool {
        return false === $current->isDot() && $current->isDir() && $current->isLink();
    }

    public static function getExtensions() {
        return self::$EXTENSIONS; 
    }

}