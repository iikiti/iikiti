<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('iikiti.security.firewall_condition')]
interface FirewallConditionInterface
{
	public function getName(): string;

	/** @param array<string,mixed> $options */
	public function validateOptions(array $options): void;

	/**
	 * Custom conditions must call {@see FirewallRequestContext::assertWithinBudget()} during long work.
	 *
	 * @param array<string,mixed> $options
	 */
	public function matches(array $options, FirewallRequestContext $context): bool;
}
