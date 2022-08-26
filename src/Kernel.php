<?php

namespace iikiti\CMS;

use iikiti\CMS\Loader\Extensions;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Yaml\Yaml;

if(!defined('BASE_DIR')) {
    define('BASE_DIR', dirname(__DIR__));
}

if(!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

/**
 *
 */
class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait {
        registerBundles as kernelRegisterBundles;
        configureRoutes as kernelConfigureRoutes;
    }

    public function process(ContainerBuilder $builder): void {
        $encoreConfig = Yaml::parseFile(
            $this->getProjectDir() . '/config/packages/webpack_encore.yaml'
        );
        $builder->setParameter(
            'webpack_encore.output_path',
            $encoreConfig['webpack_encore']['output_path']
        );
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
                    ),
                    glob(
                        $configDir . DS . 'routes.{php,yaml}',
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
        yield from Extensions::load($this);
    }

}
