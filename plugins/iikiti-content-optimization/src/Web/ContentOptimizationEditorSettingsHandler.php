<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Web;

use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Web\BlockEditor\EditorSettingsHandlerInterface;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\CMS\Web\Template\TemplateResolutionContext;
use iikiti\CMS\Web\Template\TemplateResolver;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\Extension\ContentOptimization\Security\HomepageSeoAccessPolicy;
use iikiti\Extension\ContentOptimization\Service\PageSeoSettingsNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owns `site_home` Homepage SEO settings target authorization and SEO settings
 * normalization during editor settings save/publish.
 */
final class ContentOptimizationEditorSettingsHandler implements EditorSettingsHandlerInterface
{
	public function __construct(
		private readonly PageSeoSettingsNormalizer $normalizer,
		private readonly PermissionChecker $permissionChecker,
		private readonly WorkflowAccessPolicy $workflowAccessPolicy,
		private readonly EditorSettingsManager $settingsManager,
		private readonly ?TemplateResolver $templateResolver = null,
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	public function supportsTarget(string $targetType): bool
	{
		return 'site_home' === $targetType;
	}

	public function authorizeAndLoadTarget(string $targetType, int|string $targetId, User $user, string $action): array
	{
		if (!SiteRegistry::isInitialized() || !SiteRegistry::hasCurrent()) {
			return [null, new JsonResponse(['error' => 'Settings target not found'], Response::HTTP_NOT_FOUND)];
		}
		$currentSite = SiteRegistry::getCurrent();
		// Check site-manager authority before revealing target ID or plugin status (prevents 404/403 enumeration).
		if (!$this->workflowAccessPolicy->canManageSite($user, $currentSite)) {
			return [null, new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN)];
		}
		$pluginActive = null !== $this->activationChecker
			? $this->activationChecker->isActiveForSite(HomepageSeoAccessPolicy::CONTENT_OPTIMIZATION_PLUGIN_SLUG, $currentSite)
			: in_array(HomepageSeoAccessPolicy::CONTENT_OPTIMIZATION_PLUGIN_SLUG, $currentSite->getEnabledPlugins(), true);
		if (
			(int) $currentSite->getId() !== (int) $targetId
			|| !$pluginActive
		) {
			return [null, new JsonResponse(['error' => 'Settings target not found'], Response::HTTP_NOT_FOUND)];
		}

		$homepageTemplate = $this->templateResolver?->resolve(new TemplateResolutionContext(site: $currentSite, home: true));
		$allowed = (new HomepageSeoAccessPolicy($this->permissionChecker, $this->workflowAccessPolicy, $this->activationChecker))
			->canEdit($user, $currentSite, $homepageTemplate);
		if (!$allowed) {
			return [null, new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN)];
		}

		return [$currentSite, null];
	}

	public function auditObjectType(string $targetType): ?string
	{
		return 'site_home' === $targetType ? 'Site' : null;
	}

	public function prepareSaveValues(string $targetType, DbObject $target, array $values, array $currentState): array
	{
		$normalizedSeo = $this->normalizer->normalize($values);

		return 'site_home' === $targetType
			? array_merge($this->settingsManager->state($target)['values'], $normalizedSeo)
			: array_merge($values, $normalizedSeo);
	}

	public function validateAndPreparePublish(
		string $targetType,
		DbObject $target,
		array $state,
		?string $ifMatch,
	): ?JsonResponse {
		$draft = is_array($state['values'] ?? null) ? $state['values'] : [];
		if ('site_home' === $targetType) {
			try {
				$normalizedDraft = $this->normalizer->normalize($draft);
			} catch (\InvalidArgumentException $exception) {
				return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
			}
			$storedSeoFields = array_intersect_key($draft, $normalizedDraft);
			if ($normalizedDraft !== $storedSeoFields) {
				return new JsonResponse(['error' => 'Homepage SEO settings are not normalized'], Response::HTTP_BAD_REQUEST);
			}

			return null;
		}

		if ('page' === $targetType && true === ($state['hasDraft'] ?? false)) {
			try {
				$normalizedSeo = $this->normalizer->normalize($draft);
			} catch (\InvalidArgumentException $exception) {
				return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
			}
			$storedSeoFields = array_intersect_key($draft, $normalizedSeo);
			if ($normalizedSeo !== $storedSeoFields && (null === $ifMatch || (string) ($state['version'] ?? '') === $ifMatch)) {
				$target->setProperty('settings_draft', array_merge($draft, $normalizedSeo));
			}
		}

		return null;
	}
}
