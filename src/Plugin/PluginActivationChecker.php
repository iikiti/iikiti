<?php

declare(strict_types=1);

namespace iikiti\CMS\Plugin;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\Configuration;

/**
 * Authoritative runtime checker for plugin activation across site and
 * application scopes.
 */
final class PluginActivationChecker
{
	public function __construct(
		private readonly PluginRegistry $registry,
		private readonly SiteRegistry $siteRegistry,
		private readonly ?ApplicationRegistry $applicationRegistry = null,
	) {
	}

	public function isActive(string $slug, ?string $scope = null): bool
	{
		$manifest = $this->registry->get($slug);
		if (null === $manifest) {
			return false;
		}

		$resolvedScope = $scope ?? $manifest->scope;

		return match ($resolvedScope) {
			PluginManifest::SCOPE_APPLICATION => $this->isActiveForApplication($slug),
			PluginManifest::SCOPE_BOTH => $this->siteRegistry::hasCurrent()
				? $this->isActiveForSite($slug)
				: $this->isActiveForApplication($slug),
			default => $this->isActiveForSite($slug),
		};
	}

	public function isActiveForSite(string $slug, ?Site $site = null): bool
	{
		$manifest = $this->registry->get($slug);
		if (null === $manifest) {
			return false;
		}

		if (PluginManifest::ACTIVATION_REQUIRED === $manifest->activationMode) {
			return true;
		}

		$resolvedSite = $site ?? ($this->siteRegistry::hasCurrent() ? $this->siteRegistry::getCurrent() : null);
		if (null === $resolvedSite) {
			return PluginManifest::ACTIVATION_DEFAULT_ON === $manifest->activationMode;
		}

		return $this->isActiveOnConfiguration($manifest, $resolvedSite->getConfiguration());
	}

	public function isActiveForApplication(string $slug, ?Application $application = null): bool
	{
		$manifest = $this->registry->get($slug);
		if (null === $manifest) {
			return false;
		}

		if (PluginManifest::ACTIVATION_REQUIRED === $manifest->activationMode) {
			return true;
		}

		$resolvedApp = $application;
		if (null === $resolvedApp && null !== $this->applicationRegistry && $this->siteRegistry::hasCurrent() && $this->applicationRegistry->hasCurrentApplication()) {
			$resolvedApp = $this->applicationRegistry->getCurrent();
		}

		if (null === $resolvedApp) {
			return PluginManifest::ACTIVATION_DEFAULT_ON === $manifest->activationMode;
		}

		return $this->isActiveOnConfiguration($manifest, $resolvedApp->getConfiguration());
	}

	/**
	 * Used by AdminMenuRegistry to decide whether a plugin-owned admin screen
	 * or menu item should be visible in the current admin context.
	 */
	public function isActiveForAdmin(string $slug): bool
	{
		$manifest = $this->registry->get($slug);
		if (null === $manifest) {
			return false;
		}

		if (PluginManifest::SCOPE_APPLICATION === $manifest->scope) {
			return $this->isActiveForApplication($slug);
		}

		if (PluginManifest::SCOPE_BOTH === $manifest->scope) {
			return $this->isActiveForApplication($slug)
				|| ($this->siteRegistry::hasCurrent() && $this->isActiveForSite($slug));
		}

		return $this->siteRegistry::hasCurrent()
			? $this->isActiveForSite($slug)
			: PluginManifest::ACTIVATION_OPT_IN !== $manifest->activationMode;
	}

	public function isActiveOnConfiguration(PluginManifest $manifest, Configuration $configuration): bool
	{
		if (PluginManifest::ACTIVATION_REQUIRED === $manifest->activationMode) {
			return true;
		}

		$inactive = $configuration->getInactivePlugins();
		if (in_array($manifest->slug, $inactive, true)) {
			return false;
		}

		if (PluginManifest::ACTIVATION_DEFAULT_ON === $manifest->activationMode) {
			return true;
		}

		return in_array($manifest->slug, $configuration->getActivePlugins(), true);
	}
}
