<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use iikiti\CMS\Workflow\Graph\WorkflowGraph;

final class MfaGraphProofValidator
{
	private const MAX_STATES = 4096;
	private const MAX_TOKENS = 64;

	public function __construct(private readonly MfaFactorProviderRegistry $registry)
	{
	}

	/**
	 * @param list<string> $requiredProofs
	 * @param list<string> $enabledFactors
	 */
	public function validate(WorkflowGraph $graph, array $requiredProofs, array $enabledFactors): void
	{
		$proofsByPlace = [];
		foreach ($graph->placesMetadata as $place => $metadata) {
			$factorKey = $metadata['mfaFactorKey'] ?? null;
			if (is_string($factorKey)) {
				$provider = $this->factorProvider($factorKey);
				if (in_array($factorKey, $enabledFactors, true)) {
					$proofsByPlace[$place] = $provider->getProofKey();
				}
			}
		}
		$availableProofs = array_unique(array_values($proofsByPlace));
		foreach ($requiredProofs as $proof) {
			if (!in_array($proof, $availableProofs, true)) {
				throw new \InvalidArgumentException(sprintf('MFA graph has no factor place for required proof "%s".', $proof));
			}
		}

		$queue = [[array_count_values($graph->initialPlaces), []]];
		$seen = [];
		$terminalCount = 0;
		$finalPlaces = array_fill_keys($graph->finalPlaces, true);
		while ([] !== $queue) {
			[$marking, $proofs] = array_shift($queue);
			$proofs = array_values(array_unique($proofs));
			$stateKey = $this->stateKey($marking, $proofs);
			if (isset($seen[$stateKey])) {
				continue;
			}
			$seen[$stateKey] = true;
			if (count($seen) > self::MAX_STATES) {
				throw new \InvalidArgumentException('MFA workflow graph exceeds the proof-validation complexity limit.');
			}

			if ([] !== $marking && $this->isFinalMarking($marking, $finalPlaces)) {
				++$terminalCount;
				foreach ($requiredProofs as $proof) {
					if (!in_array($proof, $proofs, true)) {
						throw new \InvalidArgumentException(sprintf('MFA graph has a terminal path without required proof "%s".', $proof));
					}
				}
				continue;
			}
			foreach (array_keys($marking) as $activePlace) {
				$factorKey = $graph->placeMetadata($activePlace)['mfaFactorKey'] ?? null;
				if (is_string($factorKey) && !in_array($factorKey, $enabledFactors, true)) {
					throw new \InvalidArgumentException(sprintf('MFA graph can activate disabled factor "%s".', $factorKey));
				}
			}

			$enabled = 0;
			foreach ($graph->transitions as $transition) {
				$disabledTarget = false;
				foreach ($transition->to as $targetPlace) {
					$factorKey = $graph->placeMetadata($targetPlace)['mfaFactorKey'] ?? null;
					if (is_string($factorKey) && !in_array($factorKey, $enabledFactors, true)) {
						$disabledTarget = true;
						break;
					}
				}
				if ($disabledTarget) {
					continue;
				}
				$sourceCounts = array_count_values($transition->from);
				if (!$this->hasTokens($marking, $sourceCounts)) {
					continue;
				}
				++$enabled;
				$nextMarking = $marking;
				$nextProofs = $proofs;
				foreach ($sourceCounts as $place => $count) {
					$nextMarking[$place] -= $count;
					if (0 === $nextMarking[$place]) {
						unset($nextMarking[$place]);
					}
					if (isset($proofsByPlace[$place])) {
						$nextProofs[] = $proofsByPlace[$place];
					}
				}
				foreach (array_count_values($transition->to) as $place => $count) {
					$nextMarking[$place] = ($nextMarking[$place] ?? 0) + $count;
				}
				if (array_sum($nextMarking) > self::MAX_TOKENS) {
					throw new \InvalidArgumentException('MFA workflow graph exceeds the parallel token limit.');
				}
				$queue[] = [$nextMarking, $nextProofs];
			}
			if (0 === $enabled) {
				throw new \InvalidArgumentException('MFA workflow graph contains a non-terminal dead end.');
			}
		}

		if (0 === $terminalCount) {
			throw new \InvalidArgumentException('MFA workflow graph has no reachable terminal marking.');
		}
	}

	private function factorProvider(string $key): MfaFactorProviderInterface
	{
		$provider = $this->registry->get($key);
		if (null === $provider) {
			throw new \InvalidArgumentException(sprintf('MFA graph references unregistered factor "%s".', $key));
		}

		return $provider;
	}

	/** @param array<string,int> $marking
	 * @param array<string,int> $sourceCounts
	 */
	private function hasTokens(array $marking, array $sourceCounts): bool
	{
		foreach ($sourceCounts as $place => $count) {
			if (($marking[$place] ?? 0) < $count) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,int> $marking
	 * @param array<string,true> $finalPlaces
	 */
	private function isFinalMarking(array $marking, array $finalPlaces): bool
	{
		foreach ($marking as $place => $tokens) {
			if ($tokens < 1 || !isset($finalPlaces[$place])) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,int> $marking
	 * @param list<string> $proofs
	 */
	private function stateKey(array $marking, array $proofs): string
	{
		ksort($marking);
		sort($proofs);

		return json_encode([$marking, $proofs], JSON_THROW_ON_ERROR);
	}
}
