<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Plugin\Lifecycle\PluginLifecycleHandler;
use iikiti\CMS\Plugin\PluginContext;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Plugin\PluginSource;
use iikiti\CMS\Plugin\PluginState;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\ResponseHeaderStore;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

#[AsController]
final class ContentOptimizationSettingsController
{
	public function __construct(
		private readonly Security $security,
		private readonly RoleHierarchyInterface $roles,
		private readonly WorkflowAccessPolicy $access,
		private readonly ApplicationRepository $applications,
		private readonly SiteRepository $sites,
		private readonly EntityManagerInterface $entityManager,
		private readonly ContentOptimizationSettings $settings,
		private readonly ?ResponseHeaderStore $responseHeaderStore = null,
		private readonly ?AuditRecorder $auditRecorder = null,
		private readonly ?PluginLifecycleHandler $lifecycle = null,
		private readonly ?PluginRegistry $plugins = null,
		#[Autowire('%kernel.environment%')]
		private readonly string $environment = 'prod',
	) {
	}

	#[Route('/api/admin/applications/{applicationId}/content-optimization/settings', name: 'api_admin_application_content_optimization_settings_get', methods: ['GET'])]
	#[Route('/api/site/{siteId}/content-optimization/settings', name: 'api_site_content_optimization_settings_get', methods: ['GET'])]
	public function get(Request $request): JsonResponse
	{
		$owner = $this->owner($request);
		if ($owner instanceof Application) {
			return new JsonResponse(['applicationId' => $owner->getId()] + $this->settings->applicationDefaults($owner) + [
				'availableConnectors' => $this->settings->availableConnectors(),
				'responseHeaderRules' => $this->xRobotsTagRules($owner),
			]);
		}

		$application = $this->sites->getApplicationBySite($owner);
		if (!$application instanceof Application) {
			throw new NotFoundHttpException('Site application not found.');
		}
		$defaults = $this->settings->applicationDefaults($application);
		$overrides = $this->settings->siteOverrides($owner);
		$resolved = $this->settings->resolve($defaults, $overrides);

		return new JsonResponse([
			'siteId' => $owner->getId(),
			'version' => 1,
			'applicationDefaults' => $defaults,
			'overrides' => (object) $overrides,
			'effective' => $resolved['effective'],
			'sources' => $resolved['sources'],
			'availableConnectors' => $this->settings->availableConnectors($owner),
			'pluginEnabled' => in_array(ContentOptimizationSettings::PLUGIN_SLUG, $owner->getEnabledPlugins(), true),
			'responseHeaderRules' => $this->xRobotsTagRules($application, $owner),
		]);
	}

	/** @return array{application:list<array<string,mixed>>,site:list<array<string,mixed>>} */
	private function xRobotsTagRules(Application $application, ?Site $site = null): array
	{
		if (null === $this->responseHeaderStore) {
			return ['application' => [], 'site' => []];
		}

		$applicationRules = $this->responseHeaderStore->getPolicy($application)['rules'];
		$siteRules = null === $site ? [] : $this->responseHeaderStore->getPolicy($site)['rules'];

		return [
			'application' => $this->filterRobotsRules($applicationRules),
			'site' => $this->filterRobotsRules($siteRules),
		];
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 *
	 * @return list<array<string,mixed>>
	 */
	private function filterRobotsRules(array $rules): array
	{
		$result = [];
		foreach ($rules as $rule) {
			if ('x-robots-tag' !== strtolower((string) ($rule['name'] ?? ''))) {
				continue;
			}
			$result[] = [
				'action' => $rule['action'],
				'value' => $rule['value'] ?? '',
				'priority' => $rule['priority'] ?? 0,
				'conditional' => isset($rule['conditions']),
				'required' => ($rule['inheritance'] ?? null) === 'required',
			];
		}

		return $result;
	}

	#[Route('/api/site/{siteId}/content-optimization/activation', name: 'api_site_content_optimization_activation_put', methods: ['PUT'])]
	public function setActivation(Request $request): JsonResponse
	{
		$owner = $this->owner($request);
		if (!$owner instanceof Site) {
			throw new NotFoundHttpException('Site not found.');
		}

		try {
			$input = $this->body($request);
			if (1 !== count(get_object_vars($input)) || !property_exists($input, 'enabled') || !is_bool($input->enabled)) {
				throw new \InvalidArgumentException('Request body must contain only an enabled boolean.');
			}
		} catch (\InvalidArgumentException $exception) {
			return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
		}

		$wasEnabled = in_array(ContentOptimizationSettings::PLUGIN_SLUG, $owner->getEnabledPlugins(), true);
		$configuration = $owner->getConfiguration();
		if ($input->enabled) {
			$configuration->enablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		} else {
			$configuration->disablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		}
		$owner->setConfiguration($configuration);
		$this->entityManager->persist($owner);
		$this->entityManager->flush();

		$isEnabled = in_array(ContentOptimizationSettings::PLUGIN_SLUG, $owner->getEnabledPlugins(), true);
		if ($isEnabled !== $wasEnabled) {
			$this->recordActivationTransition($owner, $isEnabled);
		}

		return new JsonResponse([
			'enabled' => $isEnabled,
		]);
	}

	private function recordActivationTransition(Site $site, bool $enabled): void
	{
		if (null !== $this->lifecycle) {
			$manifest = $this->plugins?->getManifests()[ContentOptimizationSettings::PLUGIN_SLUG] ?? null;
			$context = new PluginContext(
				slug: ContentOptimizationSettings::PLUGIN_SLUG,
				version: null !== $manifest ? $manifest->version : '1.0.0',
				siteId: null !== $site->getId() ? (string) $site->getId() : null,
				environment: $this->environment,
				source: PluginSource::Manual->value,
				state: PluginState::Published,
			);
			if ($enabled) {
				$this->lifecycle->activate($context);
			} else {
				$this->lifecycle->deactivate($context);
			}

			return;
		}

		$this->auditRecorder?->record(
			summary: sprintf(
				'%s Content Optimization plugin on site #%s',
				$enabled ? 'Enabled' : 'Disabled',
				(string) ($site->getId() ?? '0'),
			),
			action: $enabled ? 'enabled_plugin' : 'disabled_plugin',
			objectType: 'Site',
			objectId: $site->getId(),
			details: ['plugin' => ContentOptimizationSettings::PLUGIN_SLUG, 'enabled' => $enabled],
		);
	}

	#[Route('/api/admin/applications/{applicationId}/content-optimization/settings', name: 'api_admin_application_content_optimization_settings_put', methods: ['PUT'])]
	#[Route('/api/site/{siteId}/content-optimization/settings', name: 'api_site_content_optimization_settings_put', methods: ['PUT'])]
	public function put(Request $request): JsonResponse
	{
		$owner = $this->owner($request);
		try {
			$input = $this->body($request);
			if ($owner instanceof Application) {
				$settings = $this->settings->validateApplicationSettings($input);
				$this->settings->saveApplicationDefaults($owner, $settings);
			} else {
				$settings = $this->settings->validateSiteSettings($input, $owner);
				$this->settings->saveSiteOverrides($owner, $settings['overrides']);
			}
		} catch (\InvalidArgumentException $exception) {
			return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
		}

		$this->entityManager->persist($owner);
		$this->entityManager->flush();

		$ownerType = $owner instanceof Application ? 'Application' : 'Site';
		$this->auditRecorder?->record(
			summary: sprintf('Updated Content Optimization settings for %s #%s', $ownerType, (string) ($owner->getId() ?? '0')),
			action: 'updated_configuration',
			objectType: $ownerType,
			objectId: $owner->getId(),
			details: ['plugin' => ContentOptimizationSettings::PLUGIN_SLUG, 'scope' => strtolower($ownerType)],
		);

		return $this->get($request);
	}

	private function owner(Request $request): Application|Site
	{
		$user = $this->security->getUser();
		if (!$user instanceof User) {
			throw new AccessDeniedHttpException('Authentication is required.');
		}
		$globalRoles = UserRoleManager::convertEnumsToStrings($user->getGlobalRoles());
		$globalAdmin = in_array('ROLE_ADMIN', $this->roles->getReachableRoleNames(array_values($globalRoles)), true);

		$applicationId = $request->attributes->get('applicationId');
		if (null !== $applicationId) {
			if (!$globalAdmin) {
				throw new AccessDeniedHttpException('Global administrator authority is required.');
			}
			if (!is_scalar($applicationId) || !ctype_digit((string) $applicationId) || (int) $applicationId < 1) {
				throw new NotFoundHttpException('Application not found.');
			}
			$application = $this->applications->find($applicationId, null, null, ['filterBySite' => false]);
			if (!$application instanceof Application) {
				throw new NotFoundHttpException('Application not found.');
			}

			return $application;
		}

		if (!SiteRegistry::isInitialized() || !SiteRegistry::hasCurrent()) {
			throw new NotFoundHttpException('Site not found.');
		}
		$site = SiteRegistry::getCurrent();
		$siteId = $request->attributes->get('siteId');
		if (!is_scalar($siteId) || (string) $site->getId() !== (string) $siteId) {
			throw new NotFoundHttpException('Site not found.');
		}
		if (!$globalAdmin && !$this->access->canManageSite($user, $site)) {
			throw new AccessDeniedHttpException('Site manager authority is required.');
		}

		return $site;
	}

	private function body(Request $request): \stdClass
	{
		$content = $request->getContent();
		if (strlen($content) > 131072) {
			throw new \InvalidArgumentException('Request body exceeds 131072 bytes.');
		}
		try {
			$input = json_decode($content, false, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new \InvalidArgumentException('Invalid JSON request body.', 0, $exception);
		}
		if (!$input instanceof \stdClass) {
			throw new \InvalidArgumentException('Request body must be a JSON object.');
		}

		return $input;
	}
}
