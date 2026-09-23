<?php

namespace iikiti\CMS;

use iikiti\CMS\DependencyInjection\Compiler\DynamicRoleHierarchyPass;
use iikiti\CMS\Plugin\PluginInstallInfo;
use iikiti\CMS\Plugin\PluginLoader;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginValidator;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Yaml\Yaml;

/**
 * CMS Kernel.
 *
 * Discovers, validates and registers iikiti plugins as full Symfony bundles at
 * compile time (see {@see PluginLoader}).
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
	 * Plugins discovered during boot.
	 *
	 * @var list<array{
	 *     manifest: PluginManifest,
	 *     path: string,
	 *     installInfo: PluginInstallInfo,
	 *     allowReservedNamespace: bool
	 * }>
	 */
	private array $discoveredPlugins = [];

	/**
	 * Discovery failures, surfaced to admins without breaking the site.
	 *
	 * @var list<string>
	 */
	private array $pluginErrors = [];

	private bool $pluginsDiscovered = false;

	/**
	 * Load configurations.
	 */
	#[\Override]
	public function process(ContainerBuilder $container): void
	{
		$encoreConfig = Yaml::parseFile(
			$this->getProjectDir().'/config/packages/webpack_encore.yaml'
		);
		$container->setParameter(
			'webpack_encore.output_path',
			$encoreConfig['webpack_encore']['output_path']
		);

		$plugins = [];
		foreach ($this->discoveredPlugins as $plugin) {
			$plugins[$plugin['manifest']->slug] = [
				'manifest' => $plugin['manifest']->toArray(),
				'path' => $plugin['path'],
			];
		}
		$container->setParameter('iikiti.plugins', $plugins);
		$container->setParameter('iikiti.plugins.errors', $this->pluginErrors);
	}

	/**
	 * Register compiler passes for container compilation.
	 */
	public function build(ContainerBuilder $container): void
	{
		parent::build($container);

		$container->addCompilerPass(new DynamicRoleHierarchyPass());
	}

	/**
	 * Register plugin autoloaders and metadata before Symfony instantiates the
	 * (possibly cached) bundle list, so plugin bundle classes are always
	 * loadable and their metadata is available to the container.
	 */
	protected function initializeBundles(): void
	{
		$this->discoverPlugins();

		parent::initializeBundles();
	}

	/**
	 * Configure routes for the application and active plugins.
	 */
	protected function configureRoutes(RoutingConfigurator $routes): void
	{
		$this->__kernelConfigureRoutes($routes);

		foreach ($this->discoveredPlugins as $plugin) {
			$configDir = $plugin['path'].'/config';
			$imported = false;
			foreach (['routes.php', 'routes.yaml', 'routes.yml'] as $file) {
				$path = $configDir.'/'.$file;
				if (is_file($path)) {
					$routes->import($path);
					$imported = true;
					break;
				}
			}
			if (!$imported && is_dir($configDir.'/routes')) {
				$routes->import($configDir.'/routes', 'directory');
			}

			// Plugins may also expose attribute routes directly from their
			// controllers without any config file.
			$controllerDir = $plugin['path'].'/src/Controller';
			if (is_dir($controllerDir)) {
				$routes->import($controllerDir, 'attribute');
			}
		}
	}

	/**
	 * Registers core bundles and active plugin bundles.
	 *
	 * Plugins are discovered from `cms/extensions/active/` (symlinks into
	 * `cms/extensions/installed/`), validated, and yielded as Symfony bundles so
	 * they receive full DI, routing and event-subscriber integration.
	 */
	public function registerBundles(): iterable
	{
		yield from $this->__kernelRegisterBundles();

		$this->discoverPlugins();

		$loader = new PluginLoader(
			$this->getProjectDir(),
			new PluginValidator(),
		);

		foreach ($this->discoveredPlugins as $plugin) {
			yield $loader->instantiate($plugin);
		}
	}

	/**
	 * Discover active plugins once per kernel boot, tolerating broken plugins.
	 */
	private function discoverPlugins(): void
	{
		if ($this->pluginsDiscovered) {
			return;
		}
		$this->pluginsDiscovered = true;

		$loader = new PluginLoader(
			$this->getProjectDir(),
			new PluginValidator(),
		);

		try {
			$this->discoveredPlugins = $loader->discover();
			$this->pluginErrors = $loader->getErrors();
		} catch (\Throwable $exception) {
			// A single broken plugin must never take down the host site. Record
			// the problem so admins can see it, and boot without the plugins.
			$this->discoveredPlugins = [];
			$this->pluginErrors = array_merge($this->pluginErrors, [$exception->getMessage()]);
		}
	}
}
