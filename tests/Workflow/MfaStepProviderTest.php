<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Authentication\Mfa\MfaFactorProviderInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Step\AbstractFormWorkflowStep;
use iikiti\CMS\Workflow\Step\Mfa\MfaFactorStepAdapter;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use iikiti\CMS\Workflow\WorkflowInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class MfaStepProviderTest extends TestCase
{
	public function testBuildsFactorsFromAdminGraphAndKeepsGraphTransitions(): void
	{
		$provider = new MfaStepProvider($this->factorRegistry(), new WorkflowGraphCompiler());
		$context = [
			MfaStepProvider::CONTEXT_METHODS => ['totp'],
			MfaStepProvider::CONTEXT_GRAPH => [
				'places' => [
					['id' => 'authenticator', 'metadata' => ['mfaFactorKey' => 'totp']],
					'complete',
				],
				'transitions' => [['name' => 'verify', 'from' => 'authenticator', 'to' => 'complete']],
				'initialPlaces' => ['authenticator'],
				'finalPlaces' => ['complete'],
				'metadata' => ['policyKey' => 'core.mfa_proof_gate'],
			],
		];
		$workflow = $this->createStub(WorkflowInterface::class);

		$steps = $provider->provideSteps($workflow, $context);
		$graph = $provider->provideGraph($workflow, $context);

		self::assertCount(1, $steps);
		self::assertSame('authenticator', $steps[0]->getId());
		self::assertSame(['authenticator', 'complete'], $graph->places);
		self::assertSame('verify', $graph->transitions[0]->name);
	}

	public function testCodeDefaultGraphPreservesPreferenceOrderAndFailsOnNoFactors(): void
	{
		$provider = new MfaStepProvider($this->factorRegistry(), new WorkflowGraphCompiler());
		$workflow = $this->createStub(WorkflowInterface::class);
		$graph = $provider->provideGraph($workflow, [MfaStepProvider::CONTEXT_METHODS => ['totp']]);

		self::assertSame(['mfa_totp', 'mfa_complete'], $graph->places);
		self::assertSame(['mfa_totp'], $graph->initialPlaces);

		$this->expectException(\LogicException::class);
		$provider->provideGraph($workflow, [MfaStepProvider::CONTEXT_METHODS => []]);
	}

	public function testAdminGraphDoesNotInstantiateAnUnconfiguredFactor(): void
	{
		$provider = new MfaStepProvider($this->factorRegistry(), new WorkflowGraphCompiler());
		$workflow = $this->createStub(WorkflowInterface::class);
		$context = [
			MfaStepProvider::CONTEXT_METHODS => [],
			MfaStepProvider::CONTEXT_GRAPH => [
				'places' => [['id' => 'totp', 'metadata' => ['mfaFactorKey' => 'totp']], 'complete'],
				'transitions' => [['name' => 'verify', 'from' => 'totp', 'to' => 'complete']],
				'initialPlaces' => ['totp'],
				'finalPlaces' => ['complete'],
				'metadata' => ['policyKey' => 'core.mfa_proof_gate'],
			],
		];

		self::assertSame([], $provider->provideSteps($workflow, $context));
	}

	private function factorRegistry(): MfaFactorProviderRegistry
	{
		$factor = new class implements MfaFactorProviderInterface {
			public function getKey(): string
			{
				return 'totp';
			}

			public function getProofKey(): string
			{
				return 'totp_verified';
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
				if ('' === $placeId) {
					return null;
				}

				return new MfaFactorStepAdapter($placeId, new TestMfaFormStep());
			}
		};

		return new MfaFactorProviderRegistry([$factor]);
	}
}

final class TestMfaFormStep extends AbstractFormWorkflowStep
{
	public function __construct()
	{
		parent::__construct('factor', 'Factor', TextType::class);
	}
}
