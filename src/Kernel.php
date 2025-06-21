<?php

namespace iikiti\CMS;

use iikiti\CMS\Loader\Extensions;
use Override;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Yaml\Yaml;

/**
 * CMS Kernel.
 *
 * @uses MicroKernelTrait
 */
class Kernel extends BaseKernel implements CompilerPassInterface
{
	use MicroKernelTrait {
		MicroKernelTrait::registerBundles as private __kernelRegisterBundles;
		MicroKernelTrait::configureRoutes as private __kernelConfigureRoutes;
	}

	/**
	 * Load configurations.
	 */
	#[Override]
	public function process(ContainerBuilder $container): void
	{
		$encoreConfig = Yaml::parseFile(
			$this->getProjectDir().'/config/packages/webpack_encore.yaml'
		);
		$container->setParameter(
			'webpack_encore.output_path',
			$encoreConfig['webpack_encore']['output_path']
		);
	}

	/**
	 * Configure routes for application and extensions.
	 */
	private function configureRoutes(RoutingConfigurator $routes): void
	{
		$this->__kernelConfigureRoutes($routes);
		// TODO: $this->_configureExtensionRoutes($routes);
	}

	/**
	 * Loads routes for extensions.
	 */
	protected function _configureExtensionRoutes(RoutingConfigurator $routes): void
	{
		/** @var Extensions $extensions */
		$extensions = $this->getContainer()->get('extensions');
		foreach ($extensions->getExtensions() as $ext) {
			/** @var \Symfony\Component\HttpKernel\Bundle\AbstractBundle $ext */
			$configDir = dirname(
				(new \ReflectionClass($ext::class))->getFileName()
			).'/config';
			$routeEnvConfigs = glob(
				$configDir.'/{routes}/'.$this->environment.
				'/*.{php,yaml}',
				GLOB_BRACE
			);
			$customRouteConfigs = glob($configDir.'/{routes}/*.{php,yaml}', GLOB_BRACE);
			$defaultRouteConfig = glob($configDir.'/routes.{php,yaml}', GLOB_BRACE);
			$files = array_merge(
				$routeEnvConfigs === false ? [] : $routeEnvConfigs,
				$customRouteConfigs === false ? [] : $customRouteConfigs,
				$defaultRouteConfig === false ? [] : $defaultRouteConfig
			);
			foreach ($files as $filename) {
				$routes->import($filename);
			}
		}
	}

	#[Override]
	public function boot(): void
	{
		parent::boot();
	}

	/**
	 * Registers bundles for extensions.
	 * Will this be needed?
	 */
	public function registerBundles(): iterable
	{
		/* @var Extensions $extensions */
		// $extensions = $this->getContainer()->get('extensions');
		yield from $this->__kernelRegisterBundles();
		// TODO: yield from $extensions->load($this);
	}
}
