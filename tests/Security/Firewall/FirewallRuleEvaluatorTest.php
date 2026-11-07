<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\Condition\RequestMatchCondition;
use iikiti\CMS\Security\Firewall\FirewallAction;
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
		$condition = new class implements \iikiti\CMS\Security\Firewall\FirewallConditionInterface {
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

	private function createEvaluator(): FirewallRuleEvaluator
	{
		$registry = new FirewallConditionRegistry([new RequestMatchCondition()]);

		return new FirewallRuleEvaluator(new FirewallRuleValidator($registry), $registry);
	}
}
