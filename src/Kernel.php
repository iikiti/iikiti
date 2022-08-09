<?php

namespace iikiti\CMS;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use iikiti\CMS\Loader\Extensions;

if(!defined('BASE_DIR')) {
    define('BASE_DIR', dirname(__DIR__));
}

if(!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

/**
 *
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        registerBundles as kernelRegisterBundles;
    }

    public function boot(): void
    {
        parent::boot();
    }

    public function registerBundles(): iterable
    {
        yield from $this->kernelRegisterBundles();
        yield from Extensions::load();
    }

}
