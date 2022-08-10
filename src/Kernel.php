<?php

namespace iikiti\CMS;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use iikiti\CMS\Loader\Extensions;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

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
        configureRoutes as kernelConfigureRoutes;
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $this->kernelConfigureRoutes($routes);
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
