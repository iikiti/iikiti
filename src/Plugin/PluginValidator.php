<?php

namespace iikiti\CMS\Plugin;

use Composer\Semver\Semver;
use iikiti\CMS\Plugin\Exception\InvalidManifestException;
use iikiti\CMS\Plugin\Exception\NamespaceViolationException;
use iikiti\CMS\Plugin\Exception\PluginStateException;
use iikiti\CMS\Plugin\Exception\SecurityViolationException;

/**
 * Validates plugin manifests, namespaces, bundle classes and filesystem paths.
 *
 * All trust decisions for the plugin subsystem funnel through this class: a
 * plugin that fails validation is never autoloaded or instantiated.
 */
class PluginValidator
{
	/**
	 * Validate manifest semantics that do not require reading the filesystem.
	 *
	 * @param bool $allowReservedNamespace true only for plugins served and signed
	 *                                     by the iikiti store
	 *
	 * @throws InvalidManifestException
	 * @throws NamespaceViolationException
	 */
	public function validateManifest(PluginManifest $manifest, bool $allowReservedNamespace = false): void
	{
		if ($manifest->usesReservedVendor() && !$allowReservedNamespace) {
			throw new NamespaceViolationException(sprintf('Plugin "%s" uses the reserved "%s" vendor namespace. Only plugins built by iikiti may use it.', $manifest->slug, PluginManifest::RESERVED_VENDOR));
		}

		if (!in_array($manifest->edition, [PluginManifest::EDITION_STANDARD, PluginManifest::EDITION_PRO], true)) {
			throw new InvalidManifestException(sprintf('Plugin "%s" declares unknown edition "%s". Expected "%s" or "%s".', $manifest->slug, $manifest->edition, PluginManifest::EDITION_STANDARD, PluginManifest::EDITION_PRO));
		}
	}

	/**
	 * Validate that the declared bundle class exists, extends the plugin base
	 * class, and lives inside the declared namespace.
	 *
	 * @throws InvalidManifestException
	 */
	public function validateBundleClass(PluginManifest $manifest, string $pluginPath): void
	{
		$class = $manifest->bundleClass;

		if (!class_exists($class)) {
			throw new InvalidManifestException(sprintf('Plugin "%s" declares bundle class "%s" but it could not be autoloaded from "%s".', $manifest->slug, $class, $pluginPath));
		}

		$reflection = new \ReflectionClass($class);
		if (!$reflection->isSubclassOf(PluginBundle::class)) {
			throw new InvalidManifestException(sprintf('Bundle class "%s" must extend "%s".', $class, PluginBundle::class));
		}

		$classNamespace = $reflection->getNamespaceName();
		if (!str_starts_with($classNamespace.'\\', $manifest->namespace.'\\')) {
			throw new NamespaceViolationException(sprintf('Bundle class "%s" (namespace "%s") does not live inside the declared namespace "%s".', $class, $classNamespace, $manifest->namespace));
		}

		$classFile = $reflection->getFileName();
		if (false !== $classFile) {
			$realFile = realpath($classFile);
			$realRoot = realpath($pluginPath);
			if (false !== $realFile && false !== $realRoot && !str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR)) {
				throw new SecurityViolationException(sprintf('Bundle class "%s" resolves outside the plugin directory.', $class));
			}
		}
	}

	/**
	 * Resolve an activation symlink and guarantee it points inside the installed
	 * plugin directory. Prevents symlink traversal to arbitrary paths.
	 *
	 * @throws SecurityViolationException
	 */
	public function resolveSymlinkTarget(string $linkPath, string $installedRoot): string
	{
		if (!is_link($linkPath)) {
			throw new SecurityViolationException(sprintf('Plugin entry "%s" is not a symlink.', $linkPath));
		}

		$target = realpath($linkPath);
		$root = realpath($installedRoot);
		if (false === $target || false === $root) {
			throw new SecurityViolationException(sprintf('Plugin entry "%s" has an unresolvable symlink target.', $linkPath));
		}

		if (!is_dir($target)) {
			throw new SecurityViolationException(sprintf('Plugin entry "%s" does not resolve to a directory.', $linkPath));
		}

		if ($target !== $root && !str_starts_with($target, $root.DIRECTORY_SEPARATOR)) {
			throw new SecurityViolationException(sprintf('Plugin entry "%s" resolves to "%s", outside the installed plugin directory "%s".', $linkPath, $target, $root));
		}

		return $target;
	}

	/**
	 * Enforce a plugin's review state for the current environment.
	 *
	 * Published plugins are always allowed. Rejected plugins are never allowed.
	 * Any other state requires the explicit `PLUGIN_ALLOW_NON_APPROVED` opt-in and
	 * is still refused on production.
	 *
	 * @throws PluginStateException
	 */
	public function enforceState(
		PluginState $state,
		bool $allowNonApproved,
		string $environment,
	): void {
		if ($state->isApproved()) {
			return;
		}

		if ($state->isPermanentlyBlocked()) {
			throw new PluginStateException('Plugin has been rejected by the store and cannot be installed.');
		}

		if (!$allowNonApproved) {
			throw new PluginStateException(sprintf('Plugin state "%s" is not approved. Set PLUGIN_ALLOW_NON_APPROVED=true in a non-production environment to override.', $state->value));
		}

		if ($this->isProduction($environment)) {
			throw new PluginStateException(sprintf('Plugin state "%s" cannot be installed on a production server.', $state->value));
		}
	}

	/**
	 * Validate that all declared plugin dependencies are installed and satisfy
	 * their version constraints.
	 *
	 * @param array<string,string> $installedVersions slug => installed version
	 *
	 * @throws InvalidManifestException
	 */
	public function validateDependencies(PluginManifest $manifest, array $installedVersions): void
	{
		foreach ($manifest->dependencies as $slug => $constraint) {
			if (!isset($installedVersions[$slug])) {
				throw new InvalidManifestException(sprintf('Plugin "%s" requires "%s" (%s) which is not installed.', $manifest->slug, $slug, $constraint));
			}

			if (!Semver::satisfies($installedVersions[$slug], $constraint)) {
				throw new InvalidManifestException(sprintf('Plugin "%s" requires "%s" %s but version %s is installed.', $manifest->slug, $slug, $constraint, $installedVersions[$slug]));
			}
		}
	}

	public function isProduction(string $environment): bool
	{
		return in_array(strtolower($environment), ['prod', 'production'], true);
	}
}
