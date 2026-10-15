<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Authentication\Mfa;

use iikiti\CMS\Authentication\Mfa\MfaFactorProviderInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Authentication\Mfa\MfaProofPolicy;
use iikiti\CMS\Workflow\FormStepInterface;
use PHPUnit\Framework\TestCase;

final class MfaProofPolicyTest extends TestCase
{
	public function testEveryResolvedFactorProofIsRequired(): void
	{
		$policy = new MfaProofPolicy(new MfaFactorProviderRegistry([
			new ProofFactorProvider('totp', 'totp_verified'),
			new ProofFactorProvider('email', 'email_verified'),
		]));
		$required = $policy->requiredProofKeys(['totp', 'email']);

		self::assertFalse($policy->isSatisfied($required, ['totp_verified' => true]));
		self::assertTrue($policy->isSatisfied($required, ['totp_verified' => true, 'email_verified' => true]));
		self::assertFalse($policy->isSatisfied([], []));
	}
}

final class ProofFactorProvider implements MfaFactorProviderInterface
{
	public function __construct(private readonly string $key, private readonly string $proof)
	{
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function getProofKey(): string
	{
		return $this->proof;
	}

	public function getVersion(): string
	{
		return 'test-1';
	}

	public function isAvailableFor(\iikiti\CMS\Entity\Object\User $user): bool
	{
		return true;
	}

	public function createStep(string $placeId, array $context): ?FormStepInterface
	{
		return null;
	}
}
