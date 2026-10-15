<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\StepProvider;

use iikiti\CMS\Authentication\Mfa\MfaFactorProviderInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Authentication\MfaUserConfiguration;
use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Graph\WorkflowGraphTransition;
use iikiti\CMS\Workflow\Step\DynamicFormStep;
use iikiti\CMS\Workflow\Step\Mfa\MfaVerifiedFactorStep;
use iikiti\CMS\Workflow\WorkflowGraphProviderInterface;
use iikiti\CMS\Workflow\WorkflowInterface;
use iikiti\CMS\Workflow\WorkflowStepInterface;

/**
 * Builds session-backed MFA form steps from registered factor providers and,
 * when present, an authorized/versioned workflow graph from the database.
 */
class MfaStepProvider implements WorkflowGraphProviderInterface
{
	public const WORKFLOW_NAME = 'mfa_authentication';
	public const DEFAULT_GRAPH_VERSION = 'code-default-v1';
	public const CONTEXT_METHODS = 'mfa_methods';
	public const CONTEXT_EMAIL = 'mfa_email';
	public const CONTEXT_TOTP_SECRET = 'mfa_totp_secret';
	public const CONTEXT_BACKUP_CODES = 'mfa_backup_codes';
	public const CONTEXT_GRAPH = 'mfa_graph';
	public const CONTEXT_DEFINITION_VERSION = 'mfa_definition_version';
	public const CONTEXT_POLICY_FINGERPRINT = 'mfa_policy_fingerprint';
	public const CONTEXT_REQUIRED_PROOFS = 'mfa_required_proofs';

	public function __construct(
		private readonly MfaFactorProviderRegistry $factors,
		private readonly WorkflowGraphCompiler $graphCompiler,
	) {
	}

	public function getWorkflowName(): string
	{
		return self::WORKFLOW_NAME;
	}

	public function supports(mixed $context): bool
	{
		return is_array($context) && is_array($context[self::CONTEXT_METHODS] ?? null);
	}

	/**
	 * @return list<WorkflowStepInterface>
	 */
	public function provideSteps(WorkflowInterface $workflow, mixed $context): array
	{
		if (!is_array($context) || !is_array($context[self::CONTEXT_METHODS] ?? null)) {
			return [];
		}
		$methods = array_values(array_filter($context[self::CONTEXT_METHODS], 'is_string'));
		$document = $context[self::CONTEXT_GRAPH] ?? null;
		if (is_array($document)) {
			$graph = $this->graphCompiler->compile($document);
			$steps = [];
			foreach ($graph->places as $place) {
				$metadata = $graph->placeMetadata($place);
				$factorKey = $metadata['mfaFactorKey'] ?? null;
				if (is_string($factorKey)) {
					if (!in_array($factorKey, $methods, true)) {
						continue;
					}
					$provider = $this->factors->get($factorKey);
					if (null === $provider || null === ($step = $this->createFactorStep($provider, $place, $context))) {
						throw new \LogicException(sprintf('MFA factor "%s" is unavailable for this challenge.', $factorKey));
					}
					$steps[] = $step;

					continue;
				}
				$form = $metadata['form'] ?? null;
				if (is_array($form) && is_array($form['fields'] ?? null)) {
					$steps[] = new DynamicFormStep($place, (string) ($metadata['label'] ?? $place), $form['fields']);
				}
			}

			return $steps;
		}

		$steps = [];
		foreach ($methods as $method) {
			$provider = $this->factors->get($method);
			if (null === $provider) {
				throw new \LogicException(sprintf('MFA factor "%s" is not registered.', $method));
			}
			$stepId = $this->defaultStepId($method);
			$step = $this->createFactorStep($provider, $stepId, $context);
			if (null === $step) {
				throw new \LogicException(sprintf('MFA factor "%s" is not available for this challenge.', $method));
			}
			$steps[] = $step;
		}

		return $steps;
	}

	public function provideGraph(WorkflowInterface $workflow, mixed $context): WorkflowGraph
	{
		if (is_array($context) && is_array($context[self::CONTEXT_GRAPH] ?? null)) {
			return $this->graphCompiler->compile($context[self::CONTEXT_GRAPH]);
		}

		$steps = $this->provideSteps($workflow, $context);
		if ([] === $steps) {
			throw new \LogicException('MFA workflow requires at least one registered factor.');
		}
		$stepIds = array_map(static fn (WorkflowStepInterface $step): string => $step->getId(), $steps);
		$complete = 'mfa_complete';
		$places = [...$stepIds, $complete];
		$transitions = [];
		$placesMetadata = [];
		foreach ($stepIds as $index => $stepId) {
			$target = $stepIds[$index + 1] ?? $complete;
			$transitions[] = new WorkflowGraphTransition(
				sprintf('mfa_next_%d', $index),
				[$stepId],
				[$target],
				['navigation' => 'forward'],
			);
			$factorKey = $this->factorKeyForStep($stepId);
			if (null !== $factorKey) {
				$placesMetadata[$stepId] = ['mfaFactorKey' => $factorKey];
			}
		}

		return new WorkflowGraph($places, $transitions, [$stepIds[0]], [$complete], $placesMetadata, ['mode' => 'session', 'policyKey' => 'core.mfa_proof_gate']);
	}

	private function defaultStepId(string $factorKey): string
	{
		return match ($factorKey) {
			MfaUserConfiguration::METHOD_EMAIL => 'mfa_email',
			MfaUserConfiguration::METHOD_TOTP => 'mfa_totp',
			MfaUserConfiguration::METHOD_BACKUP_CODE => 'mfa_backup_code',
			default => 'mfa_'.$factorKey,
		};
	}

	private function factorKeyForStep(string $stepId): ?string
	{
		foreach ($this->factors->getKeys() as $factorKey) {
			if ($this->defaultStepId($factorKey) === $stepId) {
				return $factorKey;
			}
		}

		return null;
	}

	/** @param array<string,mixed> $context */
	private function createFactorStep(MfaFactorProviderInterface $provider, string $placeId, array $context): ?WorkflowStepInterface
	{
		$proofKey = $provider->getProofKey();
		if (true === ($context[$proofKey] ?? false)) {
			return new MfaVerifiedFactorStep($placeId, $provider->getKey(), $proofKey);
		}

		return $provider->createStep($placeId, $context);
	}
}
