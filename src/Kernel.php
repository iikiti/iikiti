<?php

namespace iikiti\CMS;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use iikiti\CMS\Loader\Extensions;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use ReflectionClass;

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
        $this->_configureExtensionRoutes($routes);
    }

    protected function _configureExtensionRoutes(RoutingConfigurator $routes): void {
        foreach(Extensions::getExtensions() as $ext) {
            /** @var \Symfony\Component\HttpKernel\Bundle\AbstractBundle $ext */
            $configDir = dirname(
                (new ReflectionClass($ext::class))->getFileName()
            ) . DS . 'config';
            foreach(
                array_merge(
                    glob(
                        $configDir . DS . '{routes}' . DS . $this->environment  . DS .
                        '*.{php,yaml}',
                        GLOB_BRACE
                    ),
                    glob(
                        $configDir . DS . '{routes}' . DS . '*.{php,yaml}',
                        GLOB_BRACE
                    )
                ) as $filename
            ) {
                $routes->import($filename);
            }
        }
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
