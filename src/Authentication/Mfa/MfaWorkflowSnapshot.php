<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use iikiti\CMS\Authentication\MfaUserConfiguration;

final readonly class MfaWorkflowSnapshot
{
	/**
	 * @param list<string>             $methods
	 * @param list<string>             $requiredProofs
	 * @param array<string,mixed>|null $graph
	 */
	public function __construct(
		public string $versionKey,
		public string $policyFingerprint,
		public array $methods,
		public array $requiredProofs,
		public ?array $graph,
		public MfaUserConfiguration $configuration,
		public ?string $email,
	) {
	}
}
