<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Authentication\Mfa;

use iikiti\CMS\Authentication\Mfa\MfaFactorProviderInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Authentication\Mfa\MfaGraphProofValidator;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use PHPUnit\Framework\TestCase;

final class MfaGraphProofValidatorTest extends TestCase
{
	public function testParallelFactorBranchesMustAllSatisfyRequiredProofsAtJoin(): void
	{
		$validator = new MfaGraphProofValidator(new MfaFactorProviderRegistry([
			new TestMfaFactorProvider('totp', 'totp_verified'),
			new TestMfaFactorProvider('email', 'email_verified'),
		]));
		$graph = (new WorkflowGraphCompiler())->compile([
			'places' => [
				'start',
				['id' => 'totp', 'metadata' => ['mfaFactorKey' => 'totp']],
				['id' => 'email', 'metadata' => ['mfaFactorKey' => 'email']],
				'totp_done', 'email_done', 'complete',
			],
			'transitions' => [
				['name' => 'fork', 'from' => 'start', 'to' => ['totp', 'email']],
				['name' => 'complete_totp', 'from' => 'totp', 'to' => 'totp_done'],
				['name' => 'complete_email', 'from' => 'email', 'to' => 'email_done'],
				['name' => 'join', 'from' => ['totp_done', 'email_done'], 'to' => 'complete', 'metadata' => ['automatic' => true]],
			],
			'initialPlaces' => ['start'],
			'finalPlaces' => ['complete'],
		]);

		$validator->validate($graph, ['totp_verified', 'email_verified'], ['totp', 'email']);
		self::assertCount(4, $graph->transitions);
	}

	public function testRejectsAnyTerminalPathThatSkipsARequiredFactor(): void
	{
		$validator = new MfaGraphProofValidator(new MfaFactorProviderRegistry([
			new TestMfaFactorProvider('totp', 'totp_verified'),
		]));
		$graph = (new WorkflowGraphCompiler())->compile([
			'places' => ['start', ['id' => 'totp', 'metadata' => ['mfaFactorKey' => 'totp']], 'complete'],
			'transitions' => [
				['name' => 'to_totp', 'from' => 'start', 'to' => 'totp'],
				['name' => 'bypass', 'from' => 'start', 'to' => 'complete'],
				['name' => 'finish', 'from' => 'totp', 'to' => 'complete'],
			],
			'initialPlaces' => ['start'],
			'finalPlaces' => ['complete'],
		]);

		$this->expectException(\InvalidArgumentException::class);
		$validator->validate($graph, ['totp_verified'], ['totp']);
	}
}

final class TestMfaFactorProvider implements MfaFactorProviderInterface
{
	public function __construct(private readonly string $key, private readonly string $proofKey)
	{
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function getProofKey(): string
	{
		return $this->proofKey;
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
