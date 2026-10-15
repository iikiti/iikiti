<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Definition;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Authentication\Mfa\MfaProofWorkflowPolicy;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Service\DigitalAssetProcessorRegistry;
use iikiti\CMS\Workflow\Action\WorkflowTransitionActionRegistry;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyRegistry;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use iikiti\CMS\Workflow\Task\WorkflowTaskAssignmentRegistry;

final class WorkflowDefinitionSynchronizer
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly WorkflowDefinitionProviderRegistry $providers,
		private readonly WorkflowGraphCompiler $graphCompiler,
		private readonly WorkflowPolicyRegistry $policyRegistry,
		private readonly WorkflowTransitionActionRegistry $actionRegistry,
		private readonly WorkflowTaskAssignmentRegistry $assignmentRegistry,
		private readonly MfaFactorProviderRegistry $mfaFactors,
		private readonly DigitalAssetProcessorRegistry $assetProcessors,
	) {
	}

	public function synchronizePluginDefinitions(): int
	{
		$entries = $this->providers->getDefinitions();
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();

		try {
			$changed = 0;
			$definitions = $this->entityManager->getRepository(WorkflowDefinition::class);
			$versions = $this->entityManager->getRepository(WorkflowDefinitionVersion::class);
			foreach ($entries as $entry) {
				$definitionData = $entry->definition;
				$isMfa = MfaStepProvider::WORKFLOW_NAME === $definitionData->key;
				if ($isMfa && MfaProofWorkflowPolicy::KEY !== $definitionData->policyKey) {
					throw new \LogicException('Plugin-provided MFA workflow graphs must declare the core MFA proof policy.');
				}
				$compiledGraph = $this->graphCompiler->compile($definitionData->graph);
				$workflowMetadata = $compiledGraph->workflowMetadata;
				if (isset($workflowMetadata['policyKey']) && $workflowMetadata['policyKey'] !== $definitionData->policyKey) {
					throw new \LogicException(sprintf('Plugin workflow "%s" declares conflicting policy keys.', $definitionData->key));
				}
				if (null === $this->policyRegistry->get($definitionData->policyKey)) {
					throw new \LogicException(sprintf('Plugin workflow "%s" declares an unregistered policy key.', $definitionData->key));
				}
				$workflowMetadata['policyKey'] = $definitionData->policyKey;
				if ($isMfa) {
					$workflowMetadata['mode'] = 'session';
				}
				$mode = $workflowMetadata['mode'] ?? 'durable';
				if (!in_array($mode, ['durable', 'session'], true)) {
					throw new \LogicException(sprintf('Plugin workflow "%s" declares an invalid execution mode.', $definitionData->key));
				}
				$assetProcessor = $workflowMetadata['assetProcessor'] ?? null;
				if (is_array($assetProcessor)) {
					$processorKey = $assetProcessor['processorKey'] ?? null;
					if (!is_string($processorKey) || !$this->assetProcessors->has($processorKey)) {
						throw new \LogicException(sprintf('Plugin workflow "%s" references an unregistered asset processor.', $definitionData->key));
					}
				}
				foreach ($compiledGraph->transitions as $transition) {
					$actionKey = $transition->metadata['actionKey'] ?? null;
					if ('session' === $mode && is_string($actionKey)) {
						throw new \LogicException(sprintf('Plugin session workflow "%s" cannot invoke durable workflow actions.', $definitionData->key));
					}
					if (is_string($actionKey) && null === $this->actionRegistry->get($actionKey)) {
						throw new \LogicException(sprintf('Plugin workflow "%s" references unregistered action "%s".', $definitionData->key, $actionKey));
					}
					foreach ($transition->metadata['guardKeys'] ?? [] as $guardKey) {
						if (null === $this->policyRegistry->get($guardKey)) {
							throw new \LogicException(sprintf('Plugin workflow "%s" references unregistered guard key "%s".', $definitionData->key, $guardKey));
						}
					}
				}
				foreach ($compiledGraph->placesMetadata as $place => $placeMetadata) {
					$factorKey = $placeMetadata['mfaFactorKey'] ?? null;
					if (MfaStepProvider::WORKFLOW_NAME === $definitionData->key && is_string($factorKey) && null === $this->mfaFactors->get($factorKey)) {
						throw new \LogicException(sprintf('Plugin MFA workflow "%s" references unregistered factor "%s".', $definitionData->key, $factorKey));
					}
					$assignmentKey = $placeMetadata['humanTask']['assignmentKey'] ?? null;
					if (is_string($assignmentKey) && null === $this->assignmentRegistry->get($assignmentKey)) {
						throw new \LogicException(sprintf('Plugin workflow "%s" place "%s" references unregistered assignment resolver "%s".', $definitionData->key, $place, $assignmentKey));
					}
					if (isset($placeMetadata['form']) && 'durable' === $mode && !isset($placeMetadata['humanTask'])) {
						throw new \LogicException(sprintf('Durable plugin form place "%s" requires a humanTask assignment.', $place));
					}
					if ('session' === $mode && isset($placeMetadata['humanTask'])) {
						throw new \LogicException(sprintf('Session plugin form place "%s" cannot be assigned as a durable task.', $place));
					}
				}
				$graph = (new \iikiti\CMS\Workflow\Graph\WorkflowGraph(
					$compiledGraph->places,
					$compiledGraph->transitions,
					$compiledGraph->initialPlaces,
					$compiledGraph->finalPlaces,
					$compiledGraph->placesMetadata,
					$workflowMetadata,
				))->toDocument();
				$sourceHash = $this->sourceHash($definitionData, $graph, $entry->sourceVersion);
				$definition = $definitions->findOneBy([
					'ownerType' => 'plugin',
					'ownerKey' => $entry->ownerKey,
					'key' => $definitionData->key,
				]);

				if (!$definition instanceof WorkflowDefinition) {
					$definition = new WorkflowDefinition($definitionData->key, $definitionData->name, 'plugin', $entry->ownerKey);
					$this->entityManager->persist($definition);
					$this->entityManager->flush();
					$this->entityManager->persist(new WorkflowDefinitionVersion(
						$definition,
						1,
						$graph,
						$definitionData->policyKey,
						$sourceHash,
					));
					++$changed;
					continue;
				}

				$definition->setName($definitionData->name);
				$currentVersion = $versions->findOneBy([
					'definition' => $definition,
					'version' => $definition->getCurrentVersion(),
				]);
				if (!$currentVersion instanceof WorkflowDefinitionVersion) {
					throw new \LogicException(sprintf('Plugin workflow "%s" has no current definition version.', $definitionData->key));
				}
				if ($currentVersion->getSourceHash() === $sourceHash && $currentVersion->getPolicyKey() === $definitionData->policyKey) {
					continue;
				}

				$version = $definition->advanceVersion();
				$this->entityManager->persist(new WorkflowDefinitionVersion(
					$definition,
					$version,
					$graph,
					$definitionData->policyKey,
					$sourceHash,
				));
				++$changed;
			}

			$this->entityManager->flush();
			$connection->commit();

			return $changed;
		} catch (\Throwable $exception) {
			$connection->rollBack();
			$this->entityManager->clear();

			throw $exception;
		}
	}

	/** @param array<string,mixed> $graph */
	private function sourceHash(WorkflowDefinitionData $definition, array $graph, string $sourceVersion): string
	{
		return hash('sha256', json_encode([
			'graph' => $graph,
			'policyKey' => $definition->policyKey,
			'sourceVersion' => $sourceVersion,
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
	}
}
