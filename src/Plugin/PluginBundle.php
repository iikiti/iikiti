<?php

namespace iikiti\CMS\Plugin;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Base class for all iikiti plugin bundles.
 *
 * Plugins are full Symfony bundles registered at compile time by the kernel.
 * The loader attaches the parsed {@see PluginManifest} before the bundle is
 * returned from `Kernel::registerBundles()`.
 *
 * By default a plugin's services are loaded from `config/services.php` or
 * `config/services.yaml` next to the bundle, using standard Symfony bundle
 * conventions. Plugins only need to override `loadExtension()` when they need
 * custom service wiring.
 */
abstract class PluginBundle extends AbstractBundle implements PluginBundleInterface
{
	private ?PluginManifest $pluginManifest = null;

	/**
	 * Attach the manifest discovered by the loader.
	 */
	public function setPluginManifest(PluginManifest $manifest): void
	{
		$this->pluginManifest = $manifest;
	}

	public function getPluginManifest(): ?PluginManifest
	{
		return $this->pluginManifest;
	}

	public function getPluginSlug(): string
	{
		if (null !== $this->pluginManifest) {
			return $this->pluginManifest->slug;
		}

		return strtolower(preg_replace('/Bundle$/', '', (new \ReflectionClass($this))->getShortName()) ?? 'plugin');
	}

	public function getPluginName(): string
	{
		if (null !== $this->pluginManifest) {
			return $this->pluginManifest->name;
		}

		return $this->getPluginSlug();
	}

	public function getPluginVersion(): string
	{
		if (null !== $this->pluginManifest) {
			return $this->pluginManifest->version;
		}

		return '0.0.0';
	}

	/**
	 * Absolute path to the plugin package root (the directory containing
	 * `plugin.json`).
	 */
	public function getPluginPath(): string
	{
		$classFile = (new \ReflectionClass($this))->getFileName();
		if (false === $classFile) {
			return $this->getPath();
		}

		// Bundle classes live in `<plugin>/src/...`, so the plugin root is two
		// levels up from the source directory.
		$sourceDir = dirname($classFile);
		$path = $sourceDir;
		while (!is_file($path.'/'.PluginManifest::FILENAME) && dirname($path) !== $path) {
			$path = dirname($path);
		}

		return is_file($path.'/'.PluginManifest::FILENAME) ? $path : $this->getPath();
	}

	/**
	 * Load the plugin's services from its conventional config directory.
	 *
	 * Subclasses may override this to provide custom wiring; the default
	 * implementation imports `config/services.{php,yaml}` when present, which
	 * keeps plugin code minimal.
	 *
	 * @param array<string,mixed> $config
	 */
	public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
	{
		$configDir = $this->getPath().'/config';
		foreach (['services.php', 'services.yaml', 'services.yml'] as $file) {
			$candidate = $configDir.'/'.$file;
			if (is_file($candidate)) {
				$container->import($candidate);
				break;
			}
		}
	}
}
