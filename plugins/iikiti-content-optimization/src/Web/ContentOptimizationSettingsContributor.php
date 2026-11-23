<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Web;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Service\LocalizedPropertyResolver;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Web\BlockEditor\EditorSettingsContextContributorInterface;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\CMS\Web\BlockEditor\FrontendConfigProvider;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\Extension\ContentOptimization\Security\HomepageSeoAccessPolicy;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Adds Homepage SEO (`site_home`) target selection and live preview defaults
 * to the block editor settings context when Content Optimization is active.
 */
final class ContentOptimizationSettingsContributor implements EditorSettingsContextContributorInterface
{
	public function __construct(
		private readonly EditorSettingsManager $settingsManager,
		private readonly FrontendConfigProvider $configProvider,
		private readonly ContentOptimizationSettings $optimizationSettings,
		private readonly ApplicationRegistry $applications,
		private readonly ?WorkflowAccessPolicy $workflowAccessPolicy = null,
		private readonly ?HomepageSeoAccessPolicy $homepageSeoAccessPolicy = null,
		private readonly ?LocalizedPropertyResolver $localizedPropertyResolver = null,
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	public function contributeSettingsContext(
		array $context,
		?DbObject $object,
		Template $template,
		?User $user,
		bool $home,
		?Site $site,
		Request $request,
		array $state = [],
	): array {
		if (!$site instanceof Site) {
			return $context;
		}

		$isContentOptimizationActive = null !== $this->activationChecker
			? $this->activationChecker->isActiveForSite(HomepageSeoAccessPolicy::CONTENT_OPTIMIZATION_PLUGIN_SLUG, $site)
			: in_array(HomepageSeoAccessPolicy::CONTENT_OPTIMIZATION_PLUGIN_SLUG, $site->getEnabledPlugins(), true);

		$targetType = (string) ($context['type'] ?? 'template');
		$canEditSiteHome = false;
		if ($home && null === $object && null !== $user) {
			$persistedTemplate = null !== $template->getId() ? $template : null;
			if (null !== $this->homepageSeoAccessPolicy) {
				$canEditSiteHome = $this->homepageSeoAccessPolicy->canEdit($user, $site, $persistedTemplate);
			} elseif (null !== $this->workflowAccessPolicy) {
				$canEditSiteHome = $isContentOptimizationActive
					&& $this->workflowAccessPolicy->canManageSite($user, $site)
					&& $this->configProvider->canAccess($user, 'Template', 'publish', $persistedTemplate);
			}
		}

		if ($canEditSiteHome) {
			$siteState = $this->settingsManager->state($site);
			$templateState = $this->settingsManager->state($template);
			$siteValues = array_filter(
				$siteState['values'],
				static fn ($v, string $key): bool => str_starts_with($key, 'iikiti_content_optimization_'),
				ARRAY_FILTER_USE_BOTH,
			);
			$targetType = 'site_home';
			$context = array_replace($context, [
				'type' => 'site_home',
				'id' => (int) ($site->getId() ?? 0),
				'label' => 'Homepage SEO Settings',
				'values' => $siteValues,
				'version' => $siteState['version'],
				'hasDraft' => $siteState['hasDraft'],
				'options' => [
					[
						'type' => 'site_home',
						'id' => (int) ($site->getId() ?? 0),
						'label' => 'Homepage SEO Settings',
						'values' => $siteValues,
						'version' => $siteState['version'],
						'hasDraft' => $siteState['hasDraft'],
					],
					[
						'type' => 'template',
						'id' => (int) ($template->getId() ?? 0),
						'label' => 'Shared Template Settings',
						'values' => $templateState['values'],
						'version' => $templateState['version'],
						'hasDraft' => $templateState['hasDraft'],
					],
				],
			]);
		}

		if (
			$isContentOptimizationActive
			&& in_array($targetType, ['page', 'site_home'], true)
		) {
			$defaults = $this->applications->hasCurrentApplication()
				? $this->optimizationSettings->applicationDefaults($this->applications->getCurrent())
				: $this->optimizationSettings->defaults();
			$overrides = $this->optimizationSettings->siteOverrides($site);
			$effective = $this->optimizationSettings->resolve($defaults, $overrides)['effective'];
			$seo = is_array($effective['seo'] ?? null) ? $effective['seo'] : [];
			$siteName = $this->siteName($site, $request);
			$baseTitle = $this->localizedProperty($template, 'title', $request) ?? $siteName;
			$pageTitle = $object instanceof Page
				? ($this->localizedProperty($object, 'title', $request) ?? $baseTitle)
				: $baseTitle;
			$context['seoPreviewDefaults'] = [
				'pageTitle' => $pageTitle,
				'siteName' => $siteName,
				'titleTemplate' => $seo['titleTemplate'] ?? '{pageTitle} | {siteName}',
				'description' => $seo['defaultDescription'] ?? '',
				'image' => $seo['defaultSocialImage'] ?? '',
			];
		}

		return $context;
	}

	private function siteName(Site $site, Request $request): string
	{
		$name = $this->localizedProperty($site, 'name', $request);
		$domain = $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue();

		return $name ?? (is_string($domain) && '' !== trim($domain) ? trim($domain) : 'iikiti');
	}

	private function localizedProperty(DbObject $object, string $name, Request $request): ?string
	{
		$property = $object->getProperties()->get($name);
		if (null === $property) {
			return null;
		}
		$value = $property->getValue();
		if (null !== $this->localizedPropertyResolver && null !== ($locale = $this->localeTag($request))) {
			$value = $this->localizedPropertyResolver->resolve($object, $property, $locale)->value ?? $value;
		}

		return is_string($value) && '' !== trim($value) ? trim($value) : null;
	}

	private function localeTag(Request $request): ?LocaleTag
	{
		try {
			return LocaleTag::tryFromString(str_replace('_', '-', $request->getLocale()));
		} catch (\InvalidArgumentException) {
			return null;
		}
	}
}
