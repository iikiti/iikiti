<?php
namespace iikiti\CMS\Registry;

use iikiti\CMS\Entity\Object\Site;
use SplStack;

abstract class SiteRegistry {

    protected static ?SplStack $siteStack = null;

    public static function init(): void {
        if(!(static::$siteStack instanceof SplStack)) {
            static::$siteStack = new SplStack();
        }
    }

    public static function getCurrentSite(): Site {
        return static::count() < 1 ? new Site() : static::$siteStack->top();
    }

    public static function pushSite(Site $site): void {
        static::$siteStack->push($site);
    }

    public static function popSite(): ?Site {
        return static::count() < 1 ? null : static::$siteStack->pop();
    }

    public static function count(): int {
        return static::$siteStack->count();
    }

}

SiteRegistry::init();
