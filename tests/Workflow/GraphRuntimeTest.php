<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Authentication\Mfa\MfaFactorProviderInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Graph\WorkflowGraphTransition;
use iikiti\CMS\Workflow\RuntimeWorkflowFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

final class GraphRuntimeTest extends TestCase
{
	public function testParallelForkAndJoinUseMultipleActivePlaces(): void
	{
		$graph = new WorkflowGraph(
			['start', 'left', 'right', 'left_done', 'right_done', 'complete'],
			[
				new WorkflowGraphTransition('fork', ['start'], ['left', 'right']),
				new WorkflowGraphTransition('finish_left', ['left'], ['left_done']),
				new WorkflowGraphTransition('finish_right', ['right'], ['right_done']),
				new WorkflowGraphTransition('join', ['left_done', 'right_done'], ['complete']),
			],
			['start'],
			['complete'],
		);
		$subject = new class {
			/** @var array<string,int> */
			private array $marking = [];

			/** @return array<string,int> */
			public function getMarking(): array
			{
				return $this->marking;
			}

			/** @param array<string,int> $marking */
			public function setMarking(array $marking): void
			{
				$this->marking = $marking;
			}
		};
		$workflow = (new RuntimeWorkflowFactory(new EventDispatcher()))->create(
			'parallel_test',
			$graph,
			new MethodMarkingStore(false, 'marking'),
		);

		$workflow->apply($subject, 'fork');
		self::assertSame(['left' => 1, 'right' => 1], $workflow->getMarking($subject)->getPlaces());
		self::assertTrue($workflow->can($subject, 'finish_left'));
		self::assertFalse($workflow->can($subject, 'join'));

		$workflow->apply($subject, 'finish_left');
		self::assertEquals(['left_done' => 1, 'right' => 1], $workflow->getMarking($subject)->getPlaces());
		self::assertFalse($workflow->can($subject, 'join'));

		$workflow->apply($subject, 'finish_right');
		self::assertTrue($workflow->can($subject, 'join'));
		$workflow->apply($subject, 'join');

		self::assertSame(['complete' => 1], $workflow->getMarking($subject)->getPlaces());
	}

	public function testGraphRejectsTransitionsToUnknownPlaces(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new WorkflowGraph(
			['start', 'complete'],
			[new WorkflowGraphTransition('invalid', ['start'], ['missing'])],
			['start'],
			['complete'],
		);
	}

	public function testGraphRequiresGloballyUniqueTransitionNamesForParallelSafety(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new WorkflowGraph(
			['left', 'right', 'complete'],
			[
				new WorkflowGraphTransition('approve', ['left'], ['complete']),
				new WorkflowGraphTransition('approve', ['right'], ['complete']),
			],
			['left', 'right'],
			['complete'],
		);
	}

	public function testCompilerNormalizesSafeAdminGraphData(): void
	{
		$graph = (new WorkflowGraphCompiler())->compile([
			'type' => 'workflow',
			'places' => [
				['id' => 'review', 'metadata' => ['label' => 'Review']],
				['id' => 'approved'],
				['id' => 'rejected'],
				['id' => 'complete'],
			],
			'transitions' => [
				['name' => 'approve', 'from' => 'review', 'to' => 'approved'],
				['name' => 'reject', 'from' => 'review', 'to' => 'rejected'],
				['name' => 'finish', 'from' => ['approved', 'rejected'], 'to' => 'complete', 'metadata' => ['automatic' => true]],
			],
			'initialPlaces' => ['review'],
			'finalPlaces' => ['complete'],
		]);

		self::assertSame(['review', 'approved', 'rejected', 'complete'], $graph->places);
		self::assertSame(['label' => 'Review'], $graph->placeMetadata('review'));
		self::assertSame(['automatic' => true], $graph->transitions[2]->metadata);
	}

	public function testCompilerAcceptsMigratedAssetProcessorGraphMetadata(): void
	{
		$graph = (new WorkflowGraphCompiler())->compile([
			'places' => ['pending', 'running', 'complete', 'failed', 'skipped'],
			'transitions' => [
				['name' => 'start', 'from' => ['pending', 'running', 'failed', 'skipped'], 'to' => 'running', 'metadata' => ['incrementAttempts' => true, 'clearLastError' => true]],
				['name' => 'complete', 'from' => 'running', 'to' => 'complete'],
				['name' => 'fail', 'from' => 'running', 'to' => 'failed', 'metadata' => ['setLastError' => true]],
				['name' => 'skip', 'from' => 'running', 'to' => 'skipped'],
			],
			'initialPlaces' => ['pending'],
			'finalPlaces' => ['complete'],
			'metadata' => [
				'policyKey' => 'core.asset_processor',
				'assetProcessor' => [
					'processorKey' => 'image_thumbnail',
					'mimePattern' => 'image/*',
					'conditions' => ['min_size' => 1000],
					'options' => ['variants' => [['name' => 'thumbnail', 'width' => 320, 'height' => 320]]],
					'allSites' => true,
				],
			],
		]);

		self::assertSame('workflow', $graph->toDocument()['type']);
		self::assertSame('image_thumbnail', $graph->workflowMetadata['assetProcessor']['processorKey']);
		self::assertTrue($graph->workflowMetadata['assetProcessor']['allSites']);
	}

	public function testCompilerRejectsUnregisteredFormTypeNames(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new WorkflowGraphCompiler())->compile([
			'places' => [['id' => 'form', 'metadata' => ['form' => ['fields' => [['name' => 'value', 'type' => 'App\\Form\\CustomType']]]]], 'complete'],
			'transitions' => [['name' => 'finish', 'from' => 'form', 'to' => 'complete']],
			'initialPlaces' => ['form'],
			'finalPlaces' => ['complete'],
		]);
	}

	#[DataProvider('reservedFormFieldNameProvider')]
	public function testCompilerRejectsSecurityReservedFormFieldNames(string $fieldName): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new WorkflowGraphCompiler())->compile([
			'places' => [['id' => 'form', 'metadata' => ['form' => ['fields' => [['name' => $fieldName]]]]], 'complete'],
			'transitions' => [['name' => 'finish', 'from' => 'form', 'to' => 'complete']],
			'initialPlaces' => ['form'],
			'finalPlaces' => ['complete'],
		]);
	}

	/** @return iterable<string,array{string}> */
	public static function reservedFormFieldNameProvider(): iterable
	{
		yield 'direct proof flag' => ['totp_verified'];
		yield 'mfa namespace' => ['mfa_email'];
		yield 'used suffix' => ['backup_code_used'];
		yield 'user id' => ['user_id'];
		yield 'claimed factor proof' => ['email_verified'];
	}

	public function testCompilerRejectsFieldNamesCollidingWithRegisteredProofKeysInMfaGraphs(): void
	{
		$provider = $this->createStub(MfaFactorProviderInterface::class);
		$provider->method('getKey')->willReturn('vendor.factor');
		$provider->method('getProofKey')->willReturn('vendor.custom_proof');
		$compiler = new WorkflowGraphCompiler(new MfaFactorProviderRegistry([$provider]));

		try {
			$compiler->compile([
				'places' => [['id' => 'form', 'metadata' => ['form' => ['fields' => [['name' => 'vendor.custom_proof']]]]], 'complete'],
				'transitions' => [['name' => 'finish', 'from' => 'form', 'to' => 'complete']],
				'initialPlaces' => ['form'],
				'finalPlaces' => ['complete'],
				'metadata' => ['policyKey' => 'core.mfa_proof_gate'],
			]);
			self::fail('A field colliding with a registered proof key must be refused in MFA graphs.');
		} catch (\InvalidArgumentException) {
			$plain = $compiler->compile([
				'places' => [['id' => 'form', 'metadata' => ['form' => ['fields' => [['name' => 'vendor.custom_proof']]]]], 'complete'],
				'transitions' => [['name' => 'finish', 'from' => 'form', 'to' => 'complete']],
				'initialPlaces' => ['form'],
				'finalPlaces' => ['complete'],
			]);
			self::assertSame('workflow', $plain->toDocument()['type']);
		}
	}
}
