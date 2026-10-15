<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Entity\Workflow\WorkflowInstanceEvent;
use iikiti\CMS\Entity\Workflow\WorkflowTask;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\DigitalAssetProcessorRegistry;
use iikiti\CMS\Workflow\Action\WorkflowTransitionActionRegistry;
use iikiti\CMS\Workflow\Definition\WorkflowDefinitionManager;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyRegistry;
use iikiti\CMS\Workflow\Runtime\WorkflowFormValidator;
use iikiti\CMS\Workflow\Runtime\WorkflowInstanceManager;
use iikiti\CMS\Workflow\Subject\WorkflowSubjectResolverRegistry;
use iikiti\CMS\Workflow\Task\WorkflowTaskAssignmentRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/workflows', name: 'api_admin_workflows_')]
final class WorkflowAdminController extends AppController
{
	#[Route('/sites', name: 'sites', methods: ['GET'])]
	public function sites(SiteRepository $sites, WorkflowAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->currentUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$records = $sites->findAll(['filterBySite' => false]);
		$manageable = array_filter($records, fn (Site $site): bool => $accessPolicy->isGlobalAdmin($user) || $accessPolicy->canManageSite($user, $site));

		return $this->json([
			'sites' => array_map(static fn (Site $site): array => [
				'id' => $site->getId(),
				'name' => $site->getProperties()->get('name')?->getValue() ?? $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? 'Site',
				'domain' => $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? '',
			], array_values($manageable)),
			'defaultSiteId' => SiteRegistry::getCurrent()->getId(),
			'canCreateCore' => $accessPolicy->isGlobalAdmin($user),
		]);
	}

	#[Route('/catalog', name: 'catalog', methods: ['GET'])]
	public function catalog(
		WorkflowAccessPolicy $accessPolicy,
		WorkflowPolicyRegistry $policies,
		WorkflowTransitionActionRegistry $actions,
		WorkflowTaskAssignmentRegistry $assignments,
		WorkflowSubjectResolverRegistry $subjects,
		MfaFactorProviderRegistry $mfaFactors,
		DigitalAssetProcessorRegistry $assetProcessors,
		WorkflowGraphCompiler $graphs,
		SiteRegistry $siteRegistry,
	): JsonResponse {
		$user = $this->currentUser();
		$user = $this->currentUser();
		if (null === $user || (!$accessPolicy->isGlobalAdmin($user) && !$accessPolicy->canManageSite($user, $siteRegistry::getCurrent()))) {
			return $this->forbidden();
		}

		return $this->json([
			'policies' => $policies->getKeys(),
			'actions' => $actions->getKeys(),
			'assignmentResolvers' => $assignments->getKeys(),
			'subjectResolvers' => $subjects->getKeys(),
			'mfaFactors' => $mfaFactors->getKeys(),
			'assetProcessors' => $assetProcessors->getKeys(),
			'formFieldTypes' => $graphs->getSupportedFormFieldTypes(),
		]);
	}

	#[Route('/definitions', name: 'definitions_index', methods: ['GET'])]
	public function definitions(Request $request, EntityManagerInterface $entityManager, SiteRepository $sites, WorkflowAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->currentUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$all = $request->query->getBoolean('all');
		if ($all && !$accessPolicy->isGlobalAdmin($user)) {
			return $this->forbidden();
		}
		$site = $all ? null : $this->requestedSite($request, $sites, $user, $accessPolicy);
		if (null === $site && !$all && !$accessPolicy->isGlobalAdmin($user)) {
			return $this->forbidden();
		}
		$repository = $entityManager->getRepository(WorkflowDefinition::class);
		$records = $all ?
			$repository->findBy([], ['name' => 'ASC']) :
			$repository->findAssignedToSite($site);

		return $this->json([
			'definitions' => array_map(fn (WorkflowDefinition $definition): array => $this->serializeDefinition($definition, $site, $accessPolicy, $user, $entityManager), $records),
			'siteId' => $site?->getId(),
			'all' => $all,
		]);
	}

	#[Route('/definitions/{id}', name: 'definitions_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
	public function showDefinition(int $id, Request $request, EntityManagerInterface $entityManager, SiteRepository $sites, WorkflowAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->currentUser();
		$definition = $entityManager->find(WorkflowDefinition::class, $id);
		if (null === $user || !$definition instanceof WorkflowDefinition) {
			return $this->notFound();
		}
		try {
			$site = $this->requestedSite($request, $sites, $user, $accessPolicy);
			if (null === $site && !$accessPolicy->isGlobalAdmin($user)) {
				return $this->forbidden();
			}
			if (null !== $site && !$definition->hasSite($site) && !$accessPolicy->canManageDefinition($user, $definition, $site)) {
				return $this->notFound();
			}

			return $this->json($this->serializeDefinition($definition, $site, $accessPolicy, $user, $entityManager, true));
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	#[Route('/definitions', name: 'definitions_create', methods: ['POST'])]
	public function createDefinition(Request $request, EntityManagerInterface $entityManager, SiteRepository $sites, WorkflowDefinitionManager $definitions, WorkflowAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->currentUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$payload = $this->payload($request);
		try {
			$key = $this->requiredString($payload, 'key');
			$name = $this->requiredString($payload, 'name');
			$graph = $payload['graph'] ?? null;
			if (!is_array($graph)) {
				throw new \InvalidArgumentException('Workflow graph must be a JSON object.');
			}
			$scope = $payload['ownerType'] ?? 'site';
			if (!in_array($scope, ['core', 'site'], true)) {
				throw new \InvalidArgumentException('ownerType must be core or site; plugins publish definitions through their provider.');
			}
			if ('core' === $scope) {
				if (!$accessPolicy->isGlobalAdmin($user)) {
					return $this->forbidden();
				}
				$assignedSites = $this->resolveSites($payload['siteIds'] ?? [], $sites, $user, $accessPolicy);
				$definition = $definitions->createCoreDefinition($user, $key, $name, $graph, $assignedSites);
			} else {
				$site = $this->requestedSite($request, $sites, $user, $accessPolicy, $payload['siteId'] ?? null);
				if (null === $site) {
					throw new \InvalidArgumentException('Site-owned workflow creation requires a siteId.');
				}
				$definition = $definitions->createSiteDefinition($user, $site, $key, $name, $graph);
			}

			return $this->json($this->serializeDefinition($definition, null, $accessPolicy, $user, $entityManager, true), Response::HTTP_CREATED);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	#[Route('/definitions/{id}', name: 'definitions_update', methods: ['PUT'], requirements: ['id' => '\\d+'])]
	public function updateDefinition(int $id, Request $request, EntityManagerInterface $entityManager, SiteRepository $sites, WorkflowDefinitionManager $manager, WorkflowAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->currentUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$definition = $entityManager->find(WorkflowDefinition::class, $id);
		if (!$definition instanceof WorkflowDefinition) {
			return $this->notFound();
		}
		$payload = $this->payload($request);
		try {
			$site = $this->requestedSite($request, $sites, $user, $accessPolicy, $payload['siteId'] ?? null);
			if (null === $site) {
				throw new \InvalidArgumentException('Updating a workflow definition requires a siteId scope.');
			}
			$version = $manager->updateDefinition(
				$user,
				$definition,
				$site,
				$this->requiredString($payload, 'name'),
				is_array($payload['graph'] ?? null) ? $payload['graph'] : throw new \InvalidArgumentException('Workflow graph must be a JSON object.'),
			);

			return $this->json(['definition' => $this->serializeDefinition($definition, $site, $accessPolicy, $user, $entityManager), 'version' => $this->serializeVersion($version, true)]);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	#[Route('/definitions/{id}/assignments/{siteId}', name: 'definitions_assign', methods: ['POST'], requirements: ['id' => '\\d+', 'siteId' => '\\d+'])]
	public function assignDefinition(int $id, int $siteId, EntityManagerInterface $entityManager, SiteRepository $sites, WorkflowDefinitionManager $manager): JsonResponse
	{
		$user = $this->currentUser();
		$definition = $entityManager->find(WorkflowDefinition::class, $id);
		$site = $sites->find($siteId);
		if (null === $user || !$definition instanceof WorkflowDefinition || !$site instanceof Site) {
			return $this->notFound();
		}
		try {
			$manager->assignToSite($user, $definition, $site);

			return $this->json(['assigned' => true, 'definitionId' => $definition->getId(), 'siteId' => $site->getId()]);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (\LogicException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	#[Route('/definitions/{id}/fork', name: 'definitions_fork', methods: ['POST'], requirements: ['id' => '\\d+'])]
	public function forkDefinition(int $id, Request $request, EntityManagerInterface $entityManager, SiteRepository $sites, WorkflowDefinitionManager $manager, WorkflowAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->currentUser();
		$source = $entityManager->find(WorkflowDefinition::class, $id);
		if (null === $user || !$source instanceof WorkflowDefinition) {
			return $this->notFound();
		}
		$payload = $this->payload($request);
		try {
			$site = $this->requestedSite($request, $sites, $user, $accessPolicy, $payload['siteId'] ?? null);
			if (null === $site) {
				throw new \InvalidArgumentException('Forking a workflow requires a siteId.');
			}
			$versionId = $payload['versionId'] ?? null;
			$sourceVersion = is_int($versionId) ? $entityManager->find(WorkflowDefinitionVersion::class, $versionId) : $entityManager->getRepository(WorkflowDefinitionVersion::class)->findOneBy(['definition' => $source, 'version' => $source->getCurrentVersion()]);
			if (!$sourceVersion instanceof WorkflowDefinitionVersion) {
				return $this->notFound();
			}
			$fork = $manager->forkToSite($user, $source, $sourceVersion, $site, $this->requiredString($payload, 'key'), $this->requiredString($payload, 'name'));

			return $this->json($this->serializeDefinition($fork, $site, $accessPolicy, $user, $entityManager, true), Response::HTTP_CREATED);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	#[Route('/definitions/{id}/archive', name: 'definitions_archive', methods: ['POST'], requirements: ['id' => '\\d+'])]
	public function archiveDefinition(int $id, Request $request, EntityManagerInterface $entityManager, SiteRepository $sites, WorkflowDefinitionManager $manager, WorkflowAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->currentUser();
		$definition = $entityManager->find(WorkflowDefinition::class, $id);
		if (null === $user || !$definition instanceof WorkflowDefinition) {
			return $this->notFound();
		}
		try {
			$site = $this->requestedSite($request, $sites, $user, $accessPolicy);
			if (null === $site) {
				throw new \InvalidArgumentException('Archiving a workflow definition requires a siteId scope.');
			}
			$manager->archiveDefinition($user, $definition, $site);

			return $this->json(['archived' => true]);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		}
	}

	#[Route('/tasks/sites', name: 'tasks_sites', methods: ['GET'])]
	public function taskSites(SiteRepository $sites, WorkflowAccessPolicy $accessPolicy): JsonResponse
	{
		$user = $this->currentUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$records = $sites->findAll(['filterBySite' => false]);
		$available = array_filter($records, static fn (Site $site): bool => $accessPolicy->isGlobalAdmin($user) || $user->registeredToSite($site->getId()));

		return $this->json([
			'sites' => array_map(static fn (Site $site): array => [
				'id' => $site->getId(),
				'name' => $site->getProperties()->get('name')?->getValue() ?? $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? 'Site',
				'domain' => $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue() ?? '',
			], array_values($available)),
			'defaultSiteId' => SiteRegistry::getCurrent()->getId(),
		]);
	}

	#[Route('/instances', name: 'instances_start', methods: ['POST'])]
	public function startInstance(Request $request, EntityManagerInterface $entityManager, SiteRepository $sites, WorkflowInstanceManager $instances, WorkflowAccessPolicy $accessPolicy, WorkflowGraphCompiler $graphs): JsonResponse
	{
		$user = $this->currentUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$payload = $this->payload($request);
		try {
			$definitionId = $payload['definitionId'] ?? null;
			if (!is_int($definitionId) && !is_string($definitionId)) {
				throw new \InvalidArgumentException('definitionId is required.');
			}
			$definition = $entityManager->find(WorkflowDefinition::class, $definitionId);
			if (!$definition instanceof WorkflowDefinition) {
				return $this->notFound();
			}
			$version = $entityManager->getRepository(WorkflowDefinitionVersion::class)->findOneBy(['definition' => $definition, 'version' => $definition->getCurrentVersion()]);
			if (!$version instanceof WorkflowDefinitionVersion) {
				return $this->notFound();
			}
			$site = $this->operationSite($request, $sites, $user, $accessPolicy, $payload['siteId'] ?? null);
			$context = $payload['context'] ?? [];
			if (!is_array($context)) {
				throw new \InvalidArgumentException('Workflow context must be a JSON object.');
			}
			$subjectType = $payload['subjectType'] ?? null;
			$subjectId = $payload['subjectId'] ?? null;
			if (null !== $subjectType && !is_string($subjectType)) {
				throw new \InvalidArgumentException('subjectType must be a registered string key.');
			}
			if (null !== $subjectId && !is_int($subjectId) && !is_string($subjectId)) {
				throw new \InvalidArgumentException('subjectId must be a string or integer ID.');
			}
			$idempotencyKey = $payload['idempotencyKey'] ?? null;
			if (null !== $idempotencyKey && !is_string($idempotencyKey)) {
				throw new \InvalidArgumentException('idempotencyKey must be a string.');
			}
			$instance = $instances->start($version, $site, $subjectType, $subjectId, $context, $user, $idempotencyKey);

			return $this->json($this->serializeInstance($instance, $instances, $user, $entityManager, $graphs), Response::HTTP_CREATED);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (\LogicException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	#[Route('/instances/{id}', name: 'instances_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
	public function showInstance(int $id, EntityManagerInterface $entityManager, WorkflowInstanceManager $instances, WorkflowGraphCompiler $graphs): JsonResponse
	{
		$user = $this->currentUser();
		if (null === $user) {
			return $this->forbidden();
		}
		$instance = $instances->getInstanceForUser($id, $user);

		return $this->json($this->serializeInstance($instance, $instances, $user, $entityManager, $graphs));
	}

	#[Route('/instances/{id}/transitions/{name}', name: 'instances_transition', methods: ['POST'], requirements: ['id' => '\\d+'])]
	public function transitionInstance(int $id, string $name, Request $request, EntityManagerInterface $entityManager, WorkflowInstanceManager $instances, WorkflowFormValidator $formValidator, WorkflowGraphCompiler $graphs): JsonResponse
	{
		$user = $this->currentUser();
		if (null === $user) {
			return $this->forbidden();
		}
		try {
			$instance = $instances->getInstanceForUser($id, $user);
			$payload = $this->payload($request);
			$data = $payload['data'] ?? [];
			if (!is_array($data)) {
				throw new \InvalidArgumentException('Workflow form data must be a JSON object.');
			}
			$formData = $formValidator->validateTransitionData($instance, $name, $data);
			$result = $instances->applyTransition($instance, $name, $user, $formData);
			if (null === $result) {
				return $this->json($this->serializeInstance($instance, $instances, $user, $entityManager, $graphs), Response::HTTP_ACCEPTED);
			}

			return $this->json($this->serializeInstance($instance, $instances, $user, $entityManager, $graphs));
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (\InvalidArgumentException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
		} catch (\LogicException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	#[Route('/tasks', name: 'tasks_inbox', methods: ['GET'])]
	public function tasks(Request $request, SiteRepository $sites, WorkflowInstanceManager $instances, WorkflowAccessPolicy $accessPolicy, WorkflowGraphCompiler $graphs): JsonResponse
	{
		$user = $this->currentUser();
		if (null === $user) {
			return $this->forbidden();
		}
		try {
			$site = $this->operationSite($request, $sites, $user, $accessPolicy);
			$tasks = $instances->getInbox($user, $site, $request->query->getInt('limit', 100));

			return $this->json(['tasks' => array_map(function (WorkflowTask $task) use ($instances, $user, $graphs): array {
				$graph = $graphs->compile($task->getInstance()->getDefinitionVersion()->getGraph());
				$enabled = $instances->getEnabledTransitionsByPlace($task->getInstance(), $user);

				return $this->serializeTask($task, $graph->placeMetadata($task->getPlaceKey())['form'] ?? null, $enabled[$task->getPlaceKey()] ?? []);
			}, $tasks), 'siteId' => $site?->getId()]);
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	#[Route('/tasks/{id}/claim', name: 'tasks_claim', methods: ['POST'], requirements: ['id' => '\\d+'])]
	public function claimTask(int $id, EntityManagerInterface $entityManager, WorkflowInstanceManager $instances): JsonResponse
	{
		$user = $this->currentUser();
		$task = $entityManager->find(WorkflowTask::class, $id);
		if (null === $user || !$task instanceof WorkflowTask) {
			return $this->notFound();
		}
		try {
			$instances->claimTask($task, $user);

			return $this->json($this->serializeTask($task));
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (\LogicException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	#[Route('/tasks/{id}/release', name: 'tasks_release', methods: ['POST'], requirements: ['id' => '\\d+'])]
	public function releaseTask(int $id, EntityManagerInterface $entityManager, WorkflowInstanceManager $instances): JsonResponse
	{
		$user = $this->currentUser();
		$task = $entityManager->find(WorkflowTask::class, $id);
		if (null === $user || !$task instanceof WorkflowTask) {
			return $this->notFound();
		}
		try {
			$instances->releaseTask($task, $user);

			return $this->json($this->serializeTask($task));
		} catch (AccessDeniedHttpException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
		} catch (\LogicException $exception) {
			return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
		} catch (OptimisticLockException $exception) {
			return $this->staleUpdate();
		}
	}

	private function currentUser(): ?User
	{
		$user = $this->getUser();

		return $user instanceof User ? $user : null;
	}

	private function requestedSite(Request $request, SiteRepository $sites, User $user, WorkflowAccessPolicy $accessPolicy, mixed $providedSiteId = null): ?Site
	{
		$siteId = $providedSiteId ?? $request->query->get('siteId');
		if (null === $siteId || '' === $siteId) {
			$site = SiteRegistry::getCurrent();
		} elseif (is_int($siteId) || is_string($siteId)) {
			$site = $sites->find($siteId);
		} else {
			throw new \InvalidArgumentException('siteId must be a string or integer ID.');
		}
		if (!$site instanceof Site) {
			return null;
		}
		if (!$accessPolicy->canManageSite($user, $site)) {
			throw new AccessDeniedHttpException('You cannot manage workflow definitions for this site.');
		}

		return $site;
	}

	private function operationSite(Request $request, SiteRepository $sites, User $user, WorkflowAccessPolicy $accessPolicy, mixed $providedSiteId = null): ?Site
	{
		$siteId = $providedSiteId ?? $request->query->get('siteId');
		if (null === $siteId || '' === $siteId) {
			$site = SiteRegistry::getCurrent();
		} elseif (is_int($siteId) || is_string($siteId)) {
			$site = $sites->find($siteId);
		} else {
			throw new \InvalidArgumentException('siteId must be a string or integer ID.');
		}
		if (!$site instanceof Site) {
			if ($accessPolicy->isGlobalAdmin($user)) {
				return null;
			}
			throw new AccessDeniedHttpException('A registered site context is required.');
		}
		if (!$accessPolicy->isGlobalAdmin($user) && !$user->registeredToSite($site->getId())) {
			throw new AccessDeniedHttpException('You are not registered to the requested site.');
		}

		return $site;
	}

	/** @return list<Site> */
	private function resolveSites(mixed $siteIds, SiteRepository $sites, User $user, WorkflowAccessPolicy $accessPolicy): array
	{
		if (!is_array($siteIds) || !array_is_list($siteIds) || count($siteIds) > 100) {
			throw new \InvalidArgumentException('siteIds must be a list of at most 100 site IDs.');
		}
		$resolved = [];
		foreach ($siteIds as $siteId) {
			if (!is_int($siteId) && !is_string($siteId)) {
				throw new \InvalidArgumentException('Each siteId must be a string or integer ID.');
			}
			$site = $sites->find($siteId);
			if (!$site instanceof Site) {
				throw new \InvalidArgumentException(sprintf('Site "%s" does not exist.', (string) $siteId));
			}
			if (!$accessPolicy->canManageSite($user, $site)) {
				throw new AccessDeniedHttpException(sprintf('You cannot assign workflows to site "%s".', (string) $siteId));
			}
			$resolved[] = $site;
		}

		return $resolved;
	}

	/** @return array<string,mixed> */
	private function serializeDefinition(WorkflowDefinition $definition, ?Site $site, WorkflowAccessPolicy $accessPolicy, User $user, EntityManagerInterface $entityManager, bool $includeGraph = false): array
	{
		$versions = $entityManager->getRepository(WorkflowDefinitionVersion::class)->findBy(['definition' => $definition], ['version' => 'DESC']);
		$latest = $versions[0] ?? null;
		$canEdit = $accessPolicy->isGlobalAdmin($user) ?
			'plugin' !== $definition->getOwnerType() :
			null !== $site && $accessPolicy->canManageDefinition($user, $definition, $site);
		$data = [
			'id' => $definition->getId(),
			'key' => $definition->getKey(),
			'name' => $definition->getName(),
			'ownerType' => $definition->getOwnerType(),
			'ownerKey' => $definition->getOwnerKey(),
			'currentVersion' => $definition->getCurrentVersion(),
			'enabled' => $definition->isEnabled(),
			'archived' => $definition->isArchived(),
			'siteIds' => array_values(array_map(static fn (Site $assignedSite): int|string|null => $assignedSite->getId(), $definition->getSites()->toArray())),
			'canEdit' => $canEdit,
			'versions' => array_map(fn (WorkflowDefinitionVersion $version): array => $this->serializeVersion($version, false), $versions),
		];
		if ($includeGraph && $latest instanceof WorkflowDefinitionVersion) {
			$data['graph'] = $latest->getGraph();
		}

		return $data;
	}

	/** @return array<string,mixed> */
	private function serializeVersion(WorkflowDefinitionVersion $version, bool $includeGraph): array
	{
		$data = ['id' => $version->getId(), 'version' => $version->getVersion(), 'policyKey' => $version->getPolicyKey(), 'sourceHash' => $version->getSourceHash()];
		if ($includeGraph) {
			$data['graph'] = $version->getGraph();
		}

		return $data;
	}

	/** @return array<string,mixed> */
	private function serializeInstance(WorkflowInstance $instance, WorkflowInstanceManager $manager, User $user, EntityManagerInterface $entityManager, WorkflowGraphCompiler $graphs): array
	{
		$graph = $graphs->compile($instance->getDefinitionVersion()->getGraph());
		$enabledByPlace = $manager->getEnabledTransitionsByPlace($instance, $user);
		$data = [
			'id' => $instance->getId(),
			'definition' => $instance->getDefinitionVersion()->getDefinition()->getKey(),
			'definitionVersion' => $instance->getDefinitionVersion()->getVersion(),
			'siteId' => $instance->getSite()?->getId(),
			'subject' => null === $instance->getSubjectType() ? null : ['type' => $instance->getSubjectType(), 'id' => $instance->getSubjectId()],
			'marking' => $instance->getMarking(),
			'executionState' => $instance->getExecutionState(),
			'pauseReason' => $instance->getPauseReason(),
			'attempts' => $instance->getAttempts(),
			'lastError' => $instance->getLastError(),
			'enabledTransitions' => $manager->getEnabledTransitions($instance, $user),
		];
		$data['tasks'] = array_map(function (WorkflowTask $task) use ($graph, $enabledByPlace): array {
			return $this->serializeTask($task, $graph->placeMetadata($task->getPlaceKey())['form'] ?? null, $enabledByPlace[$task->getPlaceKey()] ?? []);
		}, $manager->getTasksForInstance($instance, $user));
		$data['events'] = array_map($this->serializeEvent(...), $entityManager->getRepository(WorkflowInstanceEvent::class)->findBy(['instance' => $instance], ['createdAt' => 'ASC']));

		return $data;
	}

	/**
	 * @param array<string,mixed>|null $form
	 * @param list<string>             $enabledTransitions
	 *
	 * @return array<string,mixed>
	 */
	private function serializeTask(WorkflowTask $task, ?array $form = null, array $enabledTransitions = []): array
	{
		return [
			'id' => $task->getId(),
			'instanceId' => $task->getInstance()->getId(),
			'place' => $task->getPlaceKey(),
			'label' => $task->getLabel(),
			'siteId' => $task->getSite()?->getId(),
			'assigneeUserId' => $task->getAssigneeUser()?->getId(),
			'assigneeRoleKey' => $task->getAssigneeRoleKey(),
			'claimedByUserId' => $task->getClaimedBy()?->getId(),
			'status' => $task->getStatus(),
			'form' => $form,
			'enabledTransitions' => $enabledTransitions,
		];
	}

	/** @return array<string,mixed> */
	private function serializeEvent(WorkflowInstanceEvent $event): array
	{
		return [
			'id' => $event->getId(),
			'type' => $event->getEventType(),
			'transition' => $event->getTransitionName(),
			'fromMarking' => $event->getFromMarking(),
			'toMarking' => $event->getToMarking(),
			'details' => $event->getDetails(),
		];
	}

	/** @return array<string,mixed> */
	private function payload(Request $request): array
	{
		try {
			$payload = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}

		return is_array($payload) ? $payload : [];
	}

	/** @param array<string,mixed> $payload */
	private function requiredString(array $payload, string $key): string
	{
		$value = $payload[$key] ?? null;
		if (!is_string($value) || '' === trim($value)) {
			throw new \InvalidArgumentException(sprintf('Workflow field "%s" is required.', $key));
		}

		return trim($value);
	}

	private function notFound(): JsonResponse
	{
		return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
	}

	private function staleUpdate(): JsonResponse
	{
		return $this->json(['error' => 'Stale update: the workflow state changed concurrently. Reload and retry.'], Response::HTTP_CONFLICT);
	}

	private function forbidden(): JsonResponse
	{
		return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
	}
}
