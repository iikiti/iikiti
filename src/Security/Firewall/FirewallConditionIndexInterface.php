<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

/**
 * Optional contract for conditions that can be ruled out by request target
 * availability before they are evaluated.
 */
interface FirewallConditionIndexInterface
{
	/**
	 * Return every request index key under which this leaf can match. Returning
	 * an empty list treats the condition as unindexed and always keeps its rule
	 * as a candidate.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return list<string>
	 */
	public function getRequestIndexKeys(array $options): array;

	/**
	 * Return false only when request data proves this condition cannot match.
	 * Implementations must not return false for a possible match.
	 *
	 * @param array<string,mixed> $options
	 */
	public function couldMatchRequest(array $options, FirewallRequestContext $context): bool;
}
