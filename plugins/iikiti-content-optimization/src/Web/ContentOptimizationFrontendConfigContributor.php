<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Web;

use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\BlockEditor\FrontendConfigContributorInterface;

final class ContentOptimizationFrontendConfigContributor implements FrontendConfigContributorInterface
{
	public function __construct(
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	public function contributeConfig(int $contextId, string $contextType, User $user, array $settingsContext): array
	{
		$defaults = is_array($settingsContext['seoPreviewDefaults'] ?? null) ? $settingsContext['seoPreviewDefaults'] : [];
		if (!SiteRegistry::isInitialized() || !SiteRegistry::hasCurrent()) {
			return [
				'contentOptimizationEnabled' => false,
				'seoPreviewDefaults' => $defaults,
			];
		}

		$site = SiteRegistry::getCurrent();
		$enabled = null !== $this->activationChecker
			? $this->activationChecker->isActiveForSite('iikiti-content-optimization', $site)
			: in_array('iikiti-content-optimization', $site->getEnabledPlugins(), true);

		return [
			'contentOptimizationEnabled' => $enabled,
			'seoPreviewDefaults' => $defaults,
		];
	}
}
