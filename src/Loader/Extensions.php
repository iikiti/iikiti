<?php

namespace iikiti\CMS\Loader;

use iikiti\ExtensionUtilities\ExtensionInterface;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use iikiti\CMS\BASE_DIR;

abstract class Extensions {

    const PREFIX = 'iikiti\\extension\\';
    
    /**
     * Acquires list of enabled extensions and loads them via PSR-4 then
     * creates a new instance.
     *
     * @return void
     */
    public static function load(): void {
        foreach(self::_loadFromDirectory() as $ext) {
            $path = BASE_DIR . DS . 'cms' . DS . 'extensions' . DS .
                dirname($ext) . DS;
            if(is_dir($path) && file_exists($path)) {
                $class = self::_loadExtensionPsr4(new \SplFileInfo($path));
                if(false === ((new $class()) instanceof ExtensionInterface)) {
                    throw new InvalidTypeException(
                        'Extension "' . $class . '" does not implement ' .
                        ExtensionInterface::class . '.'
                    );
                }
            }
        }
    }

    protected static function _loadFromDirectory(): array {
        $path = BASE_DIR . DS . 'cms' . DS . 'extensions' . DS .
            'active' . DS;
        return [];
    }
    
    /**
     * Adds the path to the extension to the PSR-4 loader.
     *
     * @param  \SplFileInfo $path
     * @return string FQDN of class.
     */
    protected static function _loadExtensionPsr4(\SplFileInfo $path): string {
        $extName = basename($path->getPathname());
        $vendor = basename($path->getPath());
        $extPrefix = self::PREFIX . $vendor . '\\' . $extName . '\\';
        $extPath = $path->getPathname() . DS;
        PSR4LOADER->addPsr4($extPrefix, $extPath);
        self::_loadExtensionPsr4_composer($extPrefix, $extPath);
        return $extPrefix . ucfirst($extName) . 'Extension';
    }
    
    /**
     * Attempts to load any composer file PSR-4 paths as long as they have the
     * proper extension namespace prefix.
     *
     * @param string $extPrefix Prefix of extension class.
     * @param string $extPath Full path to extension.
     * @return void
     */
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