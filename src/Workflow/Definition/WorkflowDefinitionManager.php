<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Definition;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Authentication\Mfa\MfaProofWorkflowPolicy;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Service\DigitalAssetProcessorRegistry;
use iikiti\CMS\Workflow\Action\WorkflowTransitionActionRegistry;
use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Policy\SiteWorkflowPolicy;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyRegistry;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use iikiti\CMS\Workflow\Task\WorkflowTaskAssignmentRegistry;

final class WorkflowDefinitionManager
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly WorkflowGraphCompiler $graphCompiler,
		private readonly WorkflowPolicyRegistry $policyRegistry,
		private readonly WorkflowTransitionActionRegistry $actionRegistry,
		private readonly WorkflowTaskAssignmentRegistry $assignmentRegistry,
		private readonly MfaFactorProviderRegistry $mfaFactors,
		private readonly DigitalAssetProcessorRegistry $assetProcessors,
		private readonly WorkflowAccessPolicy $accessPolicy,
	) {
	}

	/**
	 * @param array<string,mixed> $graphDocument
	 * @param list<Site>          $sites
	 */
	public function createCoreDefinition(User $actor, string $key, string $name, array $graphDocument, array $sites = []): WorkflowDefinition
	{
		if (!$this->accessPolicy->isGlobalAdmin($actor)) {
			throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Only a global administrator may create core workflow definitions.');
		}
		foreach ($sites as $site) {
			if (!$this->accessPolicy->canAssignDefinition($actor, $site)) {
				throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You cannot assign this workflow to every selected site.');
			}
		}

		return $this->createDefinition('core', 'core', $key, $name, $graphDocument, $sites, $actor);
	}

	/** @param array<string,mixed> $graphDocument */
	public function createSiteDefinition(User $actor, Site $site, string $key, string $name, array $graphDocument): WorkflowDefinition
	{
		if (!$this->accessPolicy->canManageSite($actor, $site)) {
			throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You cannot manage workflows for this site.');
		}
		$siteId = $site->getId();
		if (null === $siteId) {
			throw new \InvalidArgumentException('Persist the site before creating a site-owned workflow.');
		}

		return $this->createDefinition('site', (string) $siteId, $key, $name, $graphDocument, [$site], $actor);
	}

	/** @param array<string,mixed> $graphDocument */
	public function updateDefinition(User $actor, WorkflowDefinition $definition, Site $site, string $name, array $graphDocument): WorkflowDefinitionVersion
	{
		if (!$this->accessPolicy->canManageDefinition($actor, $definition, $site)) {
			throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You cannot edit this workflow definition in the selected site scope.');
		}
		$graph = $this->prepareGraph($graphDocument, $definition->getKey());
		$definition->setName($name);
		$version = $definition->advanceVersion();
		$definitionVersion = new WorkflowDefinitionVersion($definition, $version, $graph->toDocument(), $graph->workflowMetadata['policyKey'], createdBy: $actor);
		$this->entityManager->persist($definitionVersion);
		$this->entityManager->flush();

		return $definitionVersion;
	}

	public function assignToSite(User $actor, WorkflowDefinition $definition, Site $site): void
	{
		if (!$this->accessPolicy->canAssignDefinition($actor, $site)) {
			throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You cannot assign workflow definitions to this site.');
		}
		if ('site' === $definition->getOwnerType() && $definition->getOwnerKey() !== (string) $site->getId()) {
			throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Site-owned workflow definitions cannot be assigned across sites; fork them instead.');
		}
		if ($definition->isArchived()) {
			throw new \LogicException('Archived workflow definitions cannot be assigned.');
		}
		$definition->assignToSite($site);
		$this->entityManager->flush();
	}

	public function archiveDefinition(User $actor, WorkflowDefinition $definition, Site $site): void
	{
		if (!$this->accessPolicy->canManageDefinition($actor, $definition, $site)) {
			throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You cannot archive this workflow definition.');
		}
		$definition->archive();
		$this->entityManager->flush();
	}

	public function forkToSite(User $actor, WorkflowDefinition $source, WorkflowDefinitionVersion $sourceVersion, Site $site, string $key, string $name): WorkflowDefinition
	{
		if (!$this->accessPolicy->canForkDefinition($actor, $source, $site)) {
			throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You cannot fork this workflow into the selected site.');
		}
		if ($sourceVersion->getDefinition() !== $source) {
			throw new \InvalidArgumentException('The source version does not belong to the supplied workflow.');
		}

		return $this->createDefinition('site', (string) $site->getId(), $key, $name, $sourceVersion->getGraph(), [$site], $actor);
	}

	/**
	 * @param list<Site>          $sites
	 * @param array<string,mixed> $graphDocument
	 */
	private function createDefinition(string $ownerType, string $ownerKey, string $key, string $name, array $graphDocument, array $sites, User $creator): WorkflowDefinition
	{
		$graph = $this->prepareGraph($graphDocument, $key);
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();
		try {
			$definition = new WorkflowDefinition($key, $name, $ownerType, $ownerKey);
			foreach ($sites as $site) {
				$definition->assignToSite($site);
			}
			$this->entityManager->persist($definition);
			$this->entityManager->flush();
			$this->entityManager->persist(new WorkflowDefinitionVersion(
				$definition,
				1,
				$graph->toDocument(),
				$graph->workflowMetadata['policyKey'],
				createdBy: $creator,
			));
			$this->entityManager->flush();
			$connection->commit();

			return $definition;
		} catch (\Throwable $exception) {
			$connection->rollBack();
			$this->entityManager->clear();

			throw $exception;
		}
	}

	/** @param array<string,mixed> $document */
	private function prepareGraph(array $document, string $workflowKey): WorkflowGraph
	{
		$graph = $this->graphCompiler->compile($document);
		$metadata = $graph->workflowMetadata;
		$isMfa = MfaStepProvider::WORKFLOW_NAME === $workflowKey;
		$requiredPolicy = $isMfa ? MfaProofWorkflowPolicy::KEY : null;
		if ($isMfa) {
			$metadata['mode'] = 'session';
		}
		$policyKey = $requiredPolicy ?? ($metadata['policyKey'] ?? SiteWorkflowPolicy::KEY);
		if (null !== $requiredPolicy && isset($metadata['policyKey']) && $metadata['policyKey'] !== $requiredPolicy) {
			throw new \InvalidArgumentException('MFA workflow graphs must use the core MFA proof policy.');
		}
		$assetProcessor = $metadata['assetProcessor'] ?? null;
		if (is_array($assetProcessor)) {
			$processorKey = $assetProcessor['processorKey'] ?? null;
			if (!is_string($processorKey) || !$this->assetProcessors->has($processorKey)) {
				throw new \InvalidArgumentException('Asset workflow references an unregistered processor key.');
			}
		}
		if (!is_string($policyKey) || null === $this->policyRegistry->get($policyKey)) {
			throw new \InvalidArgumentException('Workflow graph references an unregistered policy key.');
		}
		$metadata['policyKey'] = $policyKey;
		foreach ($graph->placesMetadata as $place => $placeMetadata) {
			$isForm = isset($placeMetadata['form']) || isset($placeMetadata['mfaFactorKey']);
			if ($isForm && 'durable' === $metadata['mode'] && !isset($placeMetadata['humanTask'])) {
				throw new \InvalidArgumentException(sprintf('Durable form place "%s" requires humanTask assignment metadata.', $place));
			}
			if ('session' === $metadata['mode'] && isset($placeMetadata['humanTask'])) {
				throw new \InvalidArgumentException(sprintf('Session form place "%s" cannot have durable task assignments.', $place));
			}
		}
		foreach ($graph->transitions as $transition) {
			$actionKey = $transition->metadata['actionKey'] ?? null;
			if ('session' === $metadata['mode'] && is_string($actionKey)) {
				throw new \InvalidArgumentException(sprintf('Session transition "%s" cannot invoke durable workflow actions.', $transition->name));
			}
			if (is_string($actionKey) && null === $this->actionRegistry->get($actionKey)) {
				throw new \InvalidArgumentException(sprintf('Workflow transition references unregistered action "%s".', $actionKey));
			}
			foreach ($transition->metadata['guardKeys'] ?? [] as $guardKey) {
				if (null === $this->policyRegistry->get($guardKey)) {
					throw new \InvalidArgumentException(sprintf('Workflow transition references unregistered guard policy "%s".', $guardKey));
				}
			}
		}
		foreach ($graph->placesMetadata as $place => $placeMetadata) {
			$factorKey = $placeMetadata['mfaFactorKey'] ?? null;
			if (MfaStepProvider::WORKFLOW_NAME === $workflowKey && is_string($factorKey) && null === $this->mfaFactors->get($factorKey)) {
				throw new \InvalidArgumentException(sprintf('MFA workflow place "%s" references unregistered factor "%s".', $place, $factorKey));
			}
			$assignmentKey = $placeMetadata['humanTask']['assignmentKey'] ?? null;
			if (is_string($assignmentKey) && null === $this->assignmentRegistry->get($assignmentKey)) {
				throw new \InvalidArgumentException(sprintf('Workflow place "%s" references unregistered assignment resolver "%s".', $place, $assignmentKey));
			}
		}

		return new WorkflowGraph(
			$graph->places,
			$graph->transitions,
			$graph->initialPlaces,
			$graph->finalPlaces,
			$graph->placesMetadata,
			$metadata,
		);
	}
}
