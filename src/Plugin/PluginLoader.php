<?php

namespace iikiti\CMS\Plugin;

use iikiti\CMS\Plugin\Exception\PluginException;

/**
 * Discovers active plugins on disk and turns them into registered bundles.
 *
 * Used directly by {@see \iikiti\CMS\Kernel::registerBundles()} (where no DI
 * container exists yet) and as a service by plugin management commands.
 */
class PluginLoader
{
	public const ACTIVE_DIR = 'cms/extensions/active';
	public const INSTALLED_DIR = 'cms/extensions/installed';
	public const CACHE_DIR = 'cms/extensions/cache';
	public const INSTALL_INFO_FILE = '.iikiti-install.json';

	public function __construct(
		private readonly string $projectDir,
		private readonly PluginValidator $validator,
	) {
	}

	/** @var list<string> */
	private array $errors = [];

	/**
	 * Problems encountered during the most recent discover() call.
	 *
	 * @return list<string>
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	public function getActivePath(): string
	{
		return $this->projectDir.'/'.self::ACTIVE_DIR;
	}

	public function getInstalledPath(): string
	{
		return $this->projectDir.'/'.self::INSTALLED_DIR;
	}

	public function getCachePath(): string
	{
		return $this->projectDir.'/'.self::CACHE_DIR;
	}

	/**
	 * Register PSR-4 autoloaders for every active plugin without instantiating
	 * bundles.
	 *
	 * Required very early in the kernel boot: the cached bundle list
	 * (`*.bundles.php`) instantiates bundle classes directly, before
	 * registerBundles() runs. This must therefore execute first.
	 */
	public function registerAutoloaders(): void
	{
		$activePath = $this->getActivePath();
		if (!is_dir($activePath)) {
			return;
		}

		$entries = scandir($activePath);
		if (false === $entries) {
			return;
		}

		foreach ($entries as $entry) {
			if ('.' === $entry || '..' === $entry) {
				continue;
			}

			$linkPath = $activePath.'/'.$entry;
			if (!is_link($linkPath)) {
				continue;
			}

			try {
				$target = $this->validator->resolveSymlinkTarget($linkPath, $this->getInstalledPath());
				$manifest = PluginManifest::fromFile($target.'/'.PluginManifest::FILENAME);
				PluginAutoloader::registerPlugin($manifest, $target);
			} catch (\Throwable) {
				// Discovery will surface the error; skip here so we never block
				// the kernel from booting.
				continue;
			}
		}
	}

	/**
	 * Discover every active plugin.
	 *
	 * Returns plugin metadata without instantiating bundle classes; use
	 * {@see instantiate()} when actual bundle instances are required (i.e. when
	 * yielding them from the kernel). This keeps the warm-container boot path
	 * cheap.
	 *
	 * @return list<array{manifest: PluginManifest, path: string, installInfo: PluginInstallInfo, allowReservedNamespace: bool}>
	 *
	 * @throws PluginException when a plugin is present but invalid
	 */
	public function discover(): array
	{
		$this->errors = [];

		$activePath = $this->getActivePath();
		if (!is_dir($activePath)) {
			return [];
		}

		$entries = scandir($activePath);
		if (false === $entries) {
			return [];
		}

		$discovered = [];
		foreach ($entries as $entry) {
			if ('.' === $entry || '..' === $entry) {
				continue;
			}

			$linkPath = $activePath.'/'.$entry;
			if (!is_link($linkPath)) {
				continue;
			}

			try {
				$discovered[] = $this->loadPlugin($linkPath);
			} catch (PluginException $exception) {
				// Never let one broken plugin break the host site; surface the
				// problem through getErrors() instead.
				$this->errors[] = sprintf('[%s] %s', $entry, $exception->getMessage());
			}
		}

		return $discovered;
	}

	/**
	 * Instantiate a bundle for a discovered plugin.
	 *
	 * @param array{manifest: PluginManifest, path: string, installInfo: PluginInstallInfo, allowReservedNamespace: bool} $plugin
	 */
	public function instantiate(array $plugin): PluginBundle
	{
		PluginAutoloader::registerPlugin($plugin['manifest'], $plugin['path']);

		$bundleClass = $plugin['manifest']->bundleClass;
		$bundle = new $bundleClass();
		if (!$bundle instanceof PluginBundle) {
			throw new PluginException(sprintf('Bundle class "%s" must extend "%s".', $bundleClass, PluginBundle::class));
		}
		$bundle->setPluginManifest($plugin['manifest']);

		return $bundle;
	}

	/**
	 * Discover only the bundle instances, ready to be yielded from the kernel.
	 *
	 * @return list<PluginBundle>
	 */
	public function getBundles(): array
	{
		$bundles = [];
		foreach ($this->discover() as $plugin) {
			$bundles[] = $this->instantiate($plugin);
		}

		return $bundles;
	}

	/**
	 * @return array{manifest: PluginManifest, path: string, installInfo: PluginInstallInfo, allowReservedNamespace: bool}
	 *
	 * @throws PluginException
	 */
	private function loadPlugin(string $linkPath): array
	{
		// Throws if the symlink escapes the installed directory.
		$target = $this->validator->resolveSymlinkTarget($linkPath, $this->getInstalledPath());

		$manifest = PluginManifest::fromFile($target.'/'.PluginManifest::FILENAME);
		$installInfo = PluginInstallInfo::fromDirectory($target) ?? PluginInstallInfo::manual($manifest);

		$allowReserved = $installInfo->source->isTrusted();
		$this->validator->validateManifest($manifest, $allowReserved);

		PluginAutoloader::registerPlugin($manifest, $target);
		$this->validator->validateBundleClass($manifest, $target);

		return [
			'manifest' => $manifest,
			'path' => $target,
			'installInfo' => $installInfo,
			'allowReservedNamespace' => $allowReserved,
		];
	}
}
