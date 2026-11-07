<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\Condition\RequestMatchCondition;
use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\FirewallCompiledRuleSet;
use iikiti\CMS\Security\Firewall\FirewallConditionInterface;
use iikiti\CMS\Security\Firewall\FirewallConditionRegistry;
use iikiti\CMS\Security\Firewall\FirewallInspectionException;
use iikiti\CMS\Security\Firewall\FirewallRequestContext;
use iikiti\CMS\Security\Firewall\FirewallRuleEvaluator;
use iikiti\CMS\Security\Firewall\FirewallRuleValidator;
use PHPUnit\Framework\TestCase;

final class FirewallRuleEvaluatorTest extends TestCase
{
	public function testEvaluatesNestedAndOrAndReturnsDenyAction(): void
	{
		$evaluator = $this->createEvaluator();
		$rules = [[
			'id' => 'site:1:test',
			'name' => 'Suspicious post',
			'action' => 'deny',
			'conditions' => ['all' => [
				['condition' => 'request.match', 'options' => ['target' => 'method', 'operator' => 'equals', 'value' => 'POST']],
				['any' => [
					['condition' => 'request.match', 'options' => ['target' => 'field', 'name' => 'comment', 'operator' => 'contains', 'value' => '<script>']],
					['condition' => 'request.match', 'options' => ['target' => 'body', 'operator' => 'contains', 'value' => 'javascript:']],
				]],
			]],
		]];
		$context = new FirewallRequestContext(
			['field' => [['name' => 'comment', 'value' => '<script>alert(1)</script>']]],
			1,
			'POST',
			'/contact',
			'127.0.0.1',
		);

		$result = $evaluator->evaluate($rules, $context);

		self::assertCount(1, $result->findings);
		self::assertSame(FirewallAction::Deny, $result->getAction());
		self::assertSame('site:1:test', $result->findings[0]->ruleId);
	}

	public function testAnyGroupShortCircuitsAfterFirstMatch(): void
	{
		$condition = new class implements FirewallConditionInterface {
			public int $calls = 0;

			public function getName(): string
			{
				return 'test.condition';
			}

			public function validateOptions(array $options): void
			{
			}

			/** @param array<string,mixed> $options */
			public function matches(array $options, FirewallRequestContext $context): bool
			{
				++$this->calls;

				return true;
			}
		};
		$registry = new FirewallConditionRegistry([$condition]);
		$evaluator = new FirewallRuleEvaluator(new FirewallRuleValidator($registry), $registry);
		$rules = [[
			'id' => 'site:1:short-circuit',
			'name' => 'Short circuit',
			'action' => 'report',
			'conditions' => ['any' => [
				['condition' => 'test.condition', 'options' => []],
				['condition' => 'test.condition', 'options' => []],
			]],
		]];

		$evaluator->evaluate($rules, new FirewallRequestContext([], 1, 'GET', '/', '127.0.0.1'));

		self::assertSame(1, $condition->calls);
	}

	public function testRejectsUnknownConditionsAndOversizedTrees(): void
	{
		$validator = new FirewallRuleValidator(new FirewallConditionRegistry([new RequestMatchCondition()]));
		$this->expectException(\InvalidArgumentException::class);
		$validator->validateRules([[
			'id' => 'site:1:unknown',
			'name' => 'Unknown condition',
			'conditions' => ['condition' => 'unknown.condition', 'options' => []],
		]]);
	}

	public function testRejectsExcessiveCombinedConditionNodes(): void
	{
		$validator = new FirewallRuleValidator(new FirewallConditionRegistry([new RequestMatchCondition()]));
		$leaf = ['condition' => 'request.match', 'options' => ['target' => 'path', 'operator' => 'contains', 'value' => '/probe']];
		$rules = [];
		for ($index = 0; $index < 155; ++$index) {
			$rules[] = $this->rule('node-limit-'.$index, ['all' => array_fill(0, 64, $leaf)]);
		}

		$this->expectException(\InvalidArgumentException::class);
		$validator->validateRules($rules);
	}

	public function testCoreRulesReportCommonPayloadsWithoutBlockingByDefault(): void
	{
		$registry = new FirewallConditionRegistry([new RequestMatchCondition()]);
		$evaluator = new FirewallRuleEvaluator(new FirewallRuleValidator($registry), $registry);
		$context = new FirewallRequestContext(
			['query' => [['name' => 'q', 'value' => "' UNION SELECT password FROM users -- "]]],
			1,
			'GET',
			'/search',
			'127.0.0.1',
		);

		$result = $evaluator->evaluate((new \iikiti\CMS\Security\Firewall\CoreFirewallRules())->getRules(), $context);

		self::assertNotEmpty($result->findings);
		self::assertSame(FirewallAction::Report, $result->getAction());
	}

	public function testRegexExecutionLimitFailsClosedInsteadOfBecomingANonMatch(): void
	{
		$registry = new FirewallConditionRegistry([new RequestMatchCondition()]);
		$evaluator = new FirewallRuleEvaluator(new FirewallRuleValidator($registry), $registry);
		$rules = [[
			'id' => 'site:1:regex-limit',
			'name' => 'Bounded regex',
			'action' => 'deny',
			'conditions' => ['condition' => 'request.match', 'options' => [
				'target' => 'body', 'operator' => 'regex', 'value' => '(a+)+$', 'caseSensitive' => true,
			]],
		]];
		$context = new FirewallRequestContext(
			['body' => [['name' => null, 'value' => str_repeat('a', 1000).'!']]],
			1,
			'POST',
			'/',
			'127.0.0.1',
		);

		$this->expectException(FirewallInspectionException::class);
		$evaluator->evaluate($rules, $context);
	}

	public function testCompiledIndexExcludesIrrelevantTargetNamesAndExactValues(): void
	{
		$condition = new RequestMatchCondition();
		$registry = new FirewallConditionRegistry([$condition]);
		$rules = [
			$this->rule('missing-header', ['condition' => 'request.match', 'options' => [
				'target' => 'header', 'name' => 'x-missing', 'operator' => 'contains', 'value' => 'probe',
			]]),
			$this->rule('wrong-method', ['condition' => 'request.match', 'options' => [
				'target' => 'method', 'operator' => 'equals', 'value' => 'POST',
			]]),
			$this->rule('matching-header', ['condition' => 'request.match', 'options' => [
				'target' => 'header', 'name' => 'x-probe', 'operator' => 'equals', 'value' => 'active',
			]]),
		];
		$compiled = new FirewallCompiledRuleSet((new FirewallRuleValidator($registry))->validateRules($rules), $registry);
		$context = new FirewallRequestContext(
			['header' => [['name' => 'X-Probe', 'value' => 'active']]],
			1,
			'GET',
			'/health',
			'127.0.0.1',
		);

		$candidates = $compiled->getCandidateRules($context, $registry);

		self::assertSame(['matching-header'], array_column($candidates, 'id'));
	}

	public function testUnindexedPluginConditionsRemainCandidates(): void
	{
		$condition = new class implements FirewallConditionInterface {
			public function getName(): string
			{
				return 'test.unindexed';
			}

			/** @param array<string,mixed> $options */
			public function validateOptions(array $options): void
			{
			}

			/** @param array<string,mixed> $options */
			public function matches(array $options, FirewallRequestContext $context): bool
			{
				return true;
			}
		};
		$registry = new FirewallConditionRegistry([$condition]);
		$rules = [$this->rule('plugin-rule', ['condition' => 'test.unindexed', 'options' => []])];
		$compiled = new FirewallCompiledRuleSet((new FirewallRuleValidator($registry))->validateRules($rules), $registry);

		self::assertCount(1, $compiled->getCandidateRules(new FirewallRequestContext([], 1, 'GET', '/', ''), $registry));
	}

	public function testCompiledEvaluationDoesNotRepeatRuleValidation(): void
	{
		$condition = new class implements FirewallConditionInterface {
			public int $validationCalls = 0;
			public int $matchCalls = 0;

			public function getName(): string
			{
				return 'test.cached';
			}

			/** @param array<string,mixed> $options */
			public function validateOptions(array $options): void
			{
				++$this->validationCalls;
			}

			/** @param array<string,mixed> $options */
			public function matches(array $options, FirewallRequestContext $context): bool
			{
				++$this->matchCalls;

				return true;
			}
		};
		$registry = new FirewallConditionRegistry([$condition]);
		$validator = new FirewallRuleValidator($registry);
		$rules = [$this->rule('compiled-once', ['condition' => 'test.cached', 'options' => []])];
		$compiled = new FirewallCompiledRuleSet($validator->validateRules($rules), $registry);
		$evaluator = new FirewallRuleEvaluator($validator, $registry);
		$context = new FirewallRequestContext([], 1, 'GET', '/', '127.0.0.1');

		for ($i = 0; $i < 20; ++$i) {
			$evaluator->evaluate($compiled, $context);
		}

		self::assertSame(1, $condition->validationCalls);
		self::assertSame(20, $condition->matchCalls);
	}

	private function createEvaluator(): FirewallRuleEvaluator
	{
		$registry = new FirewallConditionRegistry([new RequestMatchCondition()]);

		return new FirewallRuleEvaluator(new FirewallRuleValidator($registry), $registry);
	}

	/**
	 * @param array<string,mixed> $conditions
	 *
	 * @return array<string,mixed>
	 */
	private function rule(string $id, array $conditions): array
	{
		return ['id' => $id, 'name' => $id, 'action' => 'report', 'conditions' => $conditions];
	}
}
