<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Authentication\Mfa\MfaFactorProviderInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Authentication\Mfa\MfaProofPolicy;
use iikiti\CMS\Authentication\Mfa\MfaProofWorkflowPolicy;
use iikiti\CMS\Tests\Fixtures\StubWorkflowStep;
use iikiti\CMS\Workflow\FormStepInterface;
use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use iikiti\CMS\Workflow\Graph\WorkflowGraphTransition;
use iikiti\CMS\Workflow\Policy\WorkflowGuardSubscriber;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyRegistry;
use iikiti\CMS\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class MfaSessionProofGateTest extends TestCase
{
	public function testFinalTransitionRequiresProofFromEveryRequiredFactor(): void
	{
		$dispatcher = $this->createDispatcher();
		$graph = $this->graph();
		$workflow = new Workflow('mfa_authentication', $dispatcher, [new StubWorkflowStep('totp')], $graph);
		$workflow->setContext([\iikiti\CMS\Workflow\StepProvider\MfaStepProvider::CONTEXT_REQUIRED_PROOFS => ['totp_verified']]);

		self::assertFalse($workflow->submitCurrentStepWithTransition([], 'verify'));
		self::assertSame('totp', $workflow->getCurrentStep()?->getId());
		self::assertFalse($workflow->isComplete());
	}

	public function testVerifiedFactorProofAllowsFinalTransition(): void
	{
		$dispatcher = $this->createDispatcher();
		$graph = $this->graph();
		$workflow = new Workflow('mfa_authentication', $dispatcher, [new StubWorkflowStep('totp', true, ['totp_verified' => true])], $graph);
		$workflow->setContext([\iikiti\CMS\Workflow\StepProvider\MfaStepProvider::CONTEXT_REQUIRED_PROOFS => ['totp_verified']]);

		self::assertTrue($workflow->submitCurrentStepWithTransition([], 'verify'));
		self::assertTrue($workflow->isComplete());
	}

	private function createDispatcher(): EventDispatcher
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
				return null;
			}
		};
		$dispatcher = new EventDispatcher();
		$dispatcher->addSubscriber(new WorkflowGuardSubscriber(new WorkflowPolicyRegistry([
			new MfaProofWorkflowPolicy(new MfaProofPolicy(new MfaFactorProviderRegistry([$factor]))),
		])));

		return $dispatcher;
	}

	private function graph(): WorkflowGraph
	{
		return new WorkflowGraph(
			['totp', 'complete'],
			[new WorkflowGraphTransition('verify', ['totp'], ['complete'])],
			['totp'],
			['complete'],
			['totp' => ['mfaFactorKey' => 'totp']],
			['policyKey' => MfaProofWorkflowPolicy::KEY],
		);
	}
}
