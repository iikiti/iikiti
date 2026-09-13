<?php

namespace iikiti\CMS\Plugin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Plugin\PluginRecord;
use iikiti\CMS\ORM\QueryBuilder as OrmQueryBuilder;
use iikiti\CMS\Plugin\Exception\PluginException;
use iikiti\CMS\Plugin\Exception\PluginNotFoundException;
use iikiti\CMS\Plugin\Exception\SecurityViolationException;
use iikiti\CMS\Plugin\Lifecycle\PluginLifecycleHandler;
use iikiti\CMS\Query\QueryBuilderFactory;
use Psr\Log\LoggerInterface;

/**
 * High-level orchestration of the plugin lifecycle.
 *
 * Handles filesystem installation, per-site activation (logical enablement),
 * batch activation/update across every site, and lifecycle event dispatch. The
 * activation symlink in `cms/extensions/active/` is synchronised so that a
 * plugin's bundle is only compiled when it is active for at least one site.
 */
class PluginManager
{
	/** @var array<string,array{manifest:PluginManifest,path:string,installInfo:?PluginInstallInfo}>|null */
	private ?array $installedCache = null;

	public function __construct(
		private readonly PluginLoader $loader,
		private readonly PluginValidator $validator,
		private readonly PluginRegistry $registry,
		private readonly PluginLifecycleHandler $lifecycle,
		private readonly PluginContainerRebuilder $rebuilder,
		private readonly QueryBuilderFactory $queryBuilderFactory,
		private readonly EntityManagerInterface $entityManager,
		private readonly string $environment,
		private readonly bool $allowNonApproved = false,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * Plugins installed on disk.
	 *
	 * @return array<string,array{manifest: PluginManifest, path: string, installInfo: ?PluginInstallInfo}>
	 */
	public function getInstalled(): array
	{
		if (null !== $this->installedCache) {
			return $this->installedCache;
		}

		$installedPath = $this->loader->getInstalledPath();
		if (!is_dir($installedPath)) {
			return $this->installedCache = [];
		}

		$entries = scandir($installedPath);
		if (false === $entries) {
			return $this->installedCache = [];
		}

		$installed = [];
		foreach ($entries as $entry) {
			if ('.' === $entry || '..' === $entry) {
				continue;
			}

			$path = $installedPath.'/'.$entry;
			if (!is_dir($path) || !is_file($path.'/'.PluginManifest::FILENAME)) {
				continue;
			}

			try {
				$manifest = PluginManifest::fromFile($path.'/'.PluginManifest::FILENAME);
			} catch (\Throwable) {
				continue;
			}

			$installed[$manifest->slug] = [
				'manifest' => $manifest,
				'path' => $path,
				'installInfo' => PluginInstallInfo::fromDirectory($path) ?? PluginInstallInfo::manual($manifest),
			];
		}

		return $this->installedCache = $installed;
	}

	public function isInstalled(string $slug): bool
	{
		return isset($this->getInstalled()[$slug]);
	}

	private function invalidateInstalledCache(): void
	{
		$this->installedCache = null;
	}

	/**
	 * Install a downloaded package onto disk.
	 *
	 * @return PluginManifest the installed plugin's manifest
	 *
	 * @throws PluginException
	 */
	public function installPackage(PluginPackage $package): PluginManifest
	{
		[$temp, $manifest] = $this->extractPackage($package);

		try {
			if ($manifest->slug !== $package->slug) {
				throw new PluginException(sprintf('Package slug "%s" does not match manifest slug "%s".', $package->slug, $manifest->slug));
			}

			$this->validateDependenciesFor($manifest, $package->slug);

			$target = $this->loader->getInstalledPath().'/'.$manifest->slug;
			$this->ensureDirectory($this->loader->getInstalledPath());
			if (is_dir($target)) {
				$this->deleteDirectory($target);
			}
			$this->moveDirectory($temp, $target);

			PluginInstallInfo::fromArray($package->toInstallInfoArray())->writeTo($target);

			$this->registry->add($manifest);
			PluginAutoloader::registerPlugin($manifest, $target);

			$this->lifecycle->install($this->contextFor($manifest, null, $package), $this->createBundle($manifest, $target));
			$this->recordInstall($manifest, $package);
		} finally {
			if (is_dir($temp)) {
				$this->deleteDirectory($temp);
			}
		}

		$this->invalidateInstalledCache();
		$this->rebuilder->schedule();

		return $manifest;
	}

	/**
	 * Enable a plugin for every site.
	 *
	 * @return int the number of sites the plugin was actually activated for
	 */
	public function activateForAllSites(string $slug): int
	{
		$count = 0;
		foreach ($this->getSites() as $site) {
			if ($this->activateForSite($slug, (string) $site->getId())) {
				++$count;
			}
		}

		$this->entityManager->flush();
		$this->syncSymlink($slug);
		$this->rebuilder->schedule();
		$this->rebuilder->flush();

		return $count;
	}

	/**
	 * Enable a plugin for a specific set of sites.
	 *
	 * @param list<string> $siteIds
	 *
	 * @return int the number of sites the plugin was actually activated for
	 */
	public function activateForSites(string $slug, array $siteIds): int
	{
		$count = 0;
		foreach ($siteIds as $siteId) {
			if ($this->activateForSite($slug, (string) $siteId)) {
				++$count;
			}
		}

		$this->entityManager->flush();
		$this->syncSymlink($slug);
		$this->rebuilder->schedule();
		$this->rebuilder->flush();

		return $count;
	}

	/**
	 * Enable a plugin for a single site.
	 *
	 * @return bool true when the plugin was newly activated, false when it was
	 *              already active
	 */
	public function activateForSite(string $slug, string $siteId): bool
	{
		$manifest = $this->requireInstalled($slug);
		$this->assertStateAllowed($manifest);

		$site = $this->findSite($siteId);
		$configuration = $site->getConfiguration();
		if ($configuration->isPluginActive($slug)) {
			return false;
		}

		$configuration->enablePlugin($slug);
		$site->setConfiguration($configuration);

		$this->lifecycle->activate($this->contextFor($manifest, $siteId), $this->createBundleFromInstalled($slug));

		return true;
	}

	/**
	 * Enable a plugin for a site, or all sites when $siteId is null.
	 */
	public function activate(string $slug, ?string $siteId = null): int
	{
		if (null === $siteId) {
			return $this->activateForAllSites($slug);
		}

		return $this->activateForSites($slug, [$siteId]);
	}

	/**
	 * Disable a plugin for every site.
	 *
	 * @return int the number of sites the plugin was actually deactivated for
	 */
	public function disableForAllSites(string $slug): int
	{
		$count = 0;
		foreach ($this->getActiveSites($slug) as $site) {
			if ($this->disableForSite($slug, (string) $site->getId())) {
				++$count;
			}
		}

		$this->entityManager->flush();
		$this->syncSymlink($slug);
		$this->rebuilder->schedule();
		$this->rebuilder->flush();

		return $count;
	}

	/**
	 * Disable a plugin for a specific set of sites.
	 *
	 * @param list<string> $siteIds
	 *
	 * @return int the number of sites the plugin was actually deactivated for
	 */
	public function disableForSites(string $slug, array $siteIds): int
	{
		$count = 0;
		foreach ($siteIds as $siteId) {
			if ($this->disableForSite($slug, (string) $siteId)) {
				++$count;
			}
		}

		$this->entityManager->flush();
		$this->syncSymlink($slug);
		$this->rebuilder->schedule();
		$this->rebuilder->flush();

		return $count;
	}

	/**
	 * Disable a plugin for a single site.
	 *
	 * @return bool true when the plugin was actually deactivated, false when it
	 *              was not active
	 */
	public function disableForSite(string $slug, string $siteId): bool
	{
		$manifest = $this->requireInstalled($slug);

		$site = $this->findSite($siteId);
		$configuration = $site->getConfiguration();
		if (!$configuration->isPluginActive($slug)) {
			return false;
		}

		$configuration->disablePlugin($slug);
		$site->setConfiguration($configuration);

		$this->lifecycle->deactivate($this->contextFor($manifest, $siteId), $this->createBundleFromInstalled($slug));

		return true;
	}

	public function disable(string $slug, ?string $siteId = null): int
	{
		if (null === $siteId) {
			return $this->disableForAllSites($slug);
		}

		return $this->disableForSites($slug, [$siteId]);
	}

	/**
	 * Upgrade an installed plugin to a new package, firing UPDATE once per active
	 * site.
	 */
	public function updatePackage(PluginPackage $package): PluginManifest
	{
		$installed = $this->getInstalled();
		if (!isset($installed[$package->slug])) {
			throw new PluginNotFoundException(sprintf('Plugin "%s" is not installed.', $package->slug));
		}

		$fromVersion = $installed[$package->slug]['manifest']->version;
		$activeSiteIds = array_map(
			static fn (Site $site): string => (string) $site->getId(),
			$this->getActiveSites($package->slug),
		);

		[$temp, $manifest] = $this->extractPackage($package);

		try {
			if ($manifest->slug !== $package->slug) {
				throw new PluginException(sprintf('Package slug "%s" does not match manifest slug "%s".', $package->slug, $manifest->slug));
			}

			$this->assertStateAllowed($manifest);
			$this->validateDependenciesFor($manifest, $package->slug);

			$target = $this->loader->getInstalledPath().'/'.$manifest->slug;
			$this->ensureDirectory($this->loader->getInstalledPath());
			if (is_dir($target)) {
				$this->deleteDirectory($target);
			}
			$this->moveDirectory($temp, $target);

			PluginInstallInfo::fromArray($package->toInstallInfoArray())->writeTo($target);

			$this->registry->add($manifest);
			PluginAutoloader::registerPlugin($manifest, $target);

			foreach ($activeSiteIds as $siteId) {
				$this->lifecycle->update(
					$this->contextFor($manifest, $siteId, $package),
					$fromVersion,
					$this->createBundle($manifest, $target),
				);
			}

			$this->recordInstall($manifest, $package);
		} finally {
			if (is_dir($temp)) {
				$this->deleteDirectory($temp);
			}
		}

		$this->invalidateInstalledCache();
		$this->syncSymlink($manifest->slug);
		$this->rebuilder->schedule();
		$this->rebuilder->flush();

		return $manifest;
	}

	/**
	 * Remove a plugin entirely: disable for all sites, fire UNINSTALL, delete the
	 * package.
	 */
	public function remove(string $slug): void
	{
		$manifest = $this->requireInstalled($slug);

		foreach ($this->getActiveSites($slug) as $site) {
			$siteId = (string) $site->getId();
			$configuration = $site->getConfiguration();
			$configuration->disablePlugin($slug);
			$site->setConfiguration($configuration);
			$this->entityManager->flush();

			$this->lifecycle->uninstall($this->contextFor($manifest, $siteId), $this->createBundleFromInstalled($slug));
		}

		$linkPath = $this->loader->getActivePath().'/'.$slug;
		if (is_link($linkPath)) {
			unlink($linkPath);
		}

		$target = $this->loader->getInstalledPath().'/'.$slug;
		if (is_dir($target)) {
			$this->deleteDirectory($target);
		}

		$this->invalidateInstalledCache();
		$this->recordRemove($slug);
		$this->rebuilder->schedule();
		$this->rebuilder->flush();
	}

	/**
	 * Sites that currently have the plugin active.
	 *
	 * @return list<Site>
	 */
	public function getActiveSites(string $slug): array
	{
		$active = [];
		foreach ($this->getSites() as $site) {
			if ($site->getConfiguration()->isPluginActive($slug)) {
				$active[] = $site;
			}
		}

		return $active;
	}

	/**
	 * @return list<Site>
	 */
	public function getSites(): array
	{
		// Use the entity manager directly rather than the Site repository: the
		// repository depends on SiteRegistry, which requires an HTTP request and
		// is therefore unavailable in CLI/worker contexts.
		$qb = new OrmQueryBuilder($this->entityManager);
		/** @var list<Site> $sites */
		$sites = $qb->select('s')->
			from(Site::class, 's')->
			getQuery()->
			getResult();

		return $sites;
	}

	/**
	 * Build a slug => active site ids map in a single pass over all sites.
	 *
	 * @param list<string>|null $slugs restrict the map to these slugs
	 *
	 * @return array<string,list<string>>
	 */
	public function getActiveSiteIdsByPlugin(?array $slugs = null): array
	{
		$map = [];
		foreach ($this->getSites() as $site) {
			$siteId = (string) $site->getId();
			foreach ($site->getConfiguration()->getActivePlugins() as $activeSlug) {
				if (null === $slugs || in_array($activeSlug, $slugs, true)) {
					$map[$activeSlug][] = $siteId;
				}
			}
		}

		return $map;
	}

	private function findSite(string $siteId): Site
	{
		$site = $this->entityManager->find(Site::class, $siteId);
		if (!$site instanceof Site) {
			throw new PluginException(sprintf('Site "%s" was not found.', $siteId));
		}

		return $site;
	}

	private function requireInstalled(string $slug): PluginManifest
	{
		$installed = $this->getInstalled();
		if (!isset($installed[$slug])) {
			throw new PluginNotFoundException(sprintf('Plugin "%s" is not installed.', $slug));
		}

		return $installed[$slug]['manifest'];
	}

	/**
	 * Enforce the plugin's store review state for the current environment.
	 *
	 * @throws Exception\PluginStateException
	 */
	private function assertStateAllowed(PluginManifest $manifest, ?PluginPackage $package = null): void
	{
		$installInfo = PluginInstallInfo::fromDirectory($this->loader->getInstalledPath().'/'.$manifest->slug) ??
			PluginInstallInfo::manual($manifest);
		$state = $installInfo->state;
		if (null !== $package) {
			$state = $package->state;
		}

		$this->validator->enforceState($state, $this->allowNonApproved, $this->environment);
	}

	/**
	 * Validate the manifest's declared plugin dependencies against the plugins
	 * installed on disk.
	 */
	private function validateDependenciesFor(PluginManifest $manifest, string $installedSlug): void
	{
		$versions = [];
		foreach ($this->getInstalled() as $slug => $plugin) {
			if ($slug === $installedSlug) {
				continue;
			}
			$versions[$slug] = $plugin['manifest']->version;
		}

		$this->validator->validateDependencies($manifest, $versions);
	}

	private function contextFor(PluginManifest $manifest, ?string $siteId, ?PluginPackage $package = null): PluginContext
	{
		$installInfo = PluginInstallInfo::fromDirectory($this->loader->getInstalledPath().'/'.$manifest->slug);

		$source = PluginSource::Manual->value;
		if (null !== $installInfo) {
			$source = $installInfo->source->value;
		}
		if (null !== $package) {
			$source = $package->source->value;
		}

		$state = PluginState::PendingReview;
		if (null !== $installInfo) {
			$state = $installInfo->state;
		}
		if (null !== $package) {
			$state = $package->state;
		}

		return new PluginContext(
			slug: $manifest->slug,
			version: $manifest->version,
			siteId: $siteId,
			environment: $this->environment,
			source: $source,
			state: $state,
		);
	}

	private function createBundleFromInstalled(string $slug): ?PluginBundle
	{
		$installed = $this->getInstalled();
		if (!isset($installed[$slug])) {
			return null;
		}

		return $this->createBundle($installed[$slug]['manifest'], $installed[$slug]['path']);
	}

	private function createBundle(PluginManifest $manifest, string $path): ?PluginBundle
	{
		PluginAutoloader::registerPlugin($manifest, $path);
		$class = $manifest->bundleClass;
		if (!class_exists($class)) {
			return null;
		}

		$bundle = new $class();
		if (!$bundle instanceof PluginBundle) {
			return null;
		}
		$bundle->setPluginManifest($manifest);

		return $bundle;
	}

	/**
	 * Ensure the activation symlink exists while the plugin is active somewhere.
	 */
	private function syncSymlink(string $slug): void
	{
		$activeSites = $this->getActiveSites($slug);
		$linkPath = $this->loader->getActivePath().'/'.$slug;
		$installed = $this->loader->getInstalledPath().'/'.$slug;

		if (count($activeSites) > 0 && is_dir($installed)) {
			$this->ensureDirectory($this->loader->getActivePath());
			if (!is_link($linkPath) && !file_exists($linkPath)) {
				symlink('../installed/'.$slug, $linkPath);
			}

			return;
		}

		if (is_link($linkPath)) {
			unlink($linkPath);
		}
	}

	/**
	 * Extract a package into a temporary directory, validate its manifest and
	 * bundle class, and return both.
	 *
	 * @return array{0: string, 1: PluginManifest}
	 *
	 * @throws PluginException
	 */
	private function extractPackage(PluginPackage $package): array
	{
		if (!is_file($package->filePath)) {
			throw new PluginNotFoundException(sprintf('Plugin package file "%s" does not exist.', $package->filePath));
		}

		$this->ensureDirectory($this->loader->getCachePath());
		$temp = $this->loader->getCachePath().'/extract-'.$package->slug.'-'.bin2hex(random_bytes(6));
		$this->ensureDirectory($temp);

		try {
			$this->extractZip($package->filePath, $temp);

			$manifest = PluginManifest::fromFile($temp.'/'.PluginManifest::FILENAME);
			$this->validator->validateManifest($manifest, $package->source->isTrusted());

			PluginAutoloader::registerPlugin($manifest, $temp);
			$this->validator->validateBundleClass($manifest, $temp);
			PluginAutoloader::unregister($manifest->namespace);
		} catch (\Throwable $exception) {
			$this->deleteDirectory($temp);
			throw $exception;
		}

		return [$temp, $manifest];
	}

	private function extractZip(string $zipPath, string $targetDir): void
	{
		if (!class_exists(\ZipArchive::class)) {
			throw new PluginException('The zip extension is required to install plugins.');
		}

		$zip = new \ZipArchive();
		if (true !== $zip->open($zipPath)) {
			throw new PluginException(sprintf('Could not open plugin package "%s".', $zipPath));
		}

		for ($i = 0; $i < $zip->numFiles; ++$i) {
			$name = $zip->getNameIndex($i);
			if (false === $name) {
				continue;
			}

			$normalized = str_replace('\\', '/', $name);
			if (str_contains($normalized, '..') || str_starts_with($normalized, '/') || 1 === preg_match('/^[A-Za-z]:/', $normalized)) {
				$zip->close();

				throw new SecurityViolationException(sprintf('Plugin package contains an unsafe path "%s".', $name));
			}
		}

		if (true !== $zip->extractTo($targetDir)) {
			$zip->close();

			throw new PluginException(sprintf('Could not extract plugin package "%s".', $zipPath));
		}

		$zip->close();
	}

	private function ensureDirectory(string $path): void
	{
		if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
			throw new PluginException(sprintf('Could not create directory "%s".', $path));
		}
	}

	/**
	 * Move a directory, falling back to a recursive copy when rename() is not
	 * possible (e.g. across filesystems).
	 */
	private function moveDirectory(string $source, string $destination): void
	{
		if (@rename($source, $destination)) {
			return;
		}

		$this->copyDirectory($source, $destination);
		$this->deleteDirectory($source);
	}

	private function copyDirectory(string $source, string $destination): void
	{
		$this->ensureDirectory($destination);
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $item) {
			/** @var \SplFileInfo $item */
			$target = $destination.'/'.$iterator->getSubPathname();
			if ($item->isDir()) {
				$this->ensureDirectory($target);
			} elseif ($item->isLink() && false !== ($linkTarget = readlink($item->getPathname()))) {
				symlink($linkTarget, $target);
			} else {
				copy($item->getPathname(), $target);
			}
		}
	}

	/**
	 * Upsert the system-level install/version record.
	 *
	 * Written through the iikiti query builder rather than the raw DBAL
	 * connection so that every identifier and value is validated/bound: table
	 * and column names are checked against the identifier pattern, and values
	 * are passed as parameters. Best-effort: failures are logged and swallowed.
	 */
	private function recordInstall(PluginManifest $manifest, PluginPackage $package): void
	{
		try {
			$table = $this->registryTableName();

			$exists = $this->queryBuilderFactory->create();
			$existing = $exists->select('id')->
				from($table)->
				where($exists->expr()->eq('slug', $manifest->slug))->
				andWhere($exists->expr()->isNull('site_id'))->
				executeQuery()->
				fetchOne();

			$data = [
				'slug' => $manifest->slug,
				'site_id' => null,
				'version' => $manifest->version,
				'source' => $package->source->value,
				'state' => $package->state->value,
				'installed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
			];

			if (false !== $existing) {
				unset($data['slug'], $data['site_id']);
				$update = $this->queryBuilderFactory->create();
				$update->update($table);
				foreach ($data as $column => $value) {
					$update->set($column, $update->parameter($value));
				}
				$update->where($update->expr()->eq('id', $existing));
				$update->executeStatement();
			} else {
				$insert = $this->queryBuilderFactory->create();
				$insert->insert($table);
				foreach ($data as $column => $value) {
					$insert->setValue($column, $insert->parameter($value));
				}
				$insert->executeStatement();
			}
		} catch (\Throwable $exception) {
			$this->logger?->warning('Could not record plugin installation.', [
				'plugin' => $manifest->slug,
				'exception' => $exception,
			]);
		}
	}

	private function recordRemove(string $slug): void
	{
		try {
			$qb = $this->queryBuilderFactory->create();
			$qb->delete($this->registryTableName())->
				where($qb->expr()->eq('slug', $slug))->
				executeStatement();
		} catch (\Throwable $exception) {
			$this->logger?->warning('Could not remove plugin records.', [
				'plugin' => $slug,
				'exception' => $exception,
			]);
		}
	}

	/**
	 * Fully-qualified plugin_registry table name, honouring the DB_SCHEMA
	 * convention applied to application entities.
	 */
	private function registryTableName(): string
	{
		try {
			$metadata = $this->entityManager->getClassMetadata(PluginRecord::class);
			$table = $metadata->getTableName();
			$schema = $metadata->getSchemaName();
		} catch (\Throwable) {
			$table = 'plugin_registry';
			$schema = null;
		}

		if (null === $schema || '' === $schema) {
			$schema = getenv('DB_SCHEMA');
			$schema = is_string($schema) ? $schema : '';
		}

		return '' === $schema ? $table : $schema.'.'.$table;
	}

	private function deleteDirectory(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $item) {
			/** @var \SplFileInfo $item */
			if ($item->isLink() || $item->isFile()) {
				unlink($item->getPathname());
			} elseif ($item->isDir()) {
				rmdir($item->getPathname());
			}
		}
		rmdir($path);
	}
}
