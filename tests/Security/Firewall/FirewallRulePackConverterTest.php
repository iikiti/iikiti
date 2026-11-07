<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\Condition\RequestMatchCondition;
use iikiti\CMS\Security\Firewall\FirewallConditionRegistry;
use iikiti\CMS\Security\Firewall\FirewallRequestContext;
use iikiti\CMS\Security\Firewall\FirewallRuleEvaluator;
use iikiti\CMS\Security\Firewall\FirewallRulePackConverter;
use iikiti\CMS\Security\Firewall\FirewallRuleValidator;
use PHPUnit\Framework\TestCase;

final class FirewallRulePackConverterTest extends TestCase
{
	public function testConvertsVersionedJsonConditionTrees(): void
	{
		$converter = $this->createConverter();
		$rules = $converter->convert('iikiti-json-v1', json_encode([
			'schemaVersion' => 1,
			'rules' => [[
				'id' => 'pack-rule',
				'name' => 'Match URI',
				'action' => 'report',
				'conditions' => ['all' => [[
					'condition' => 'request.match',
					'options' => ['target' => 'uri', 'operator' => 'contains', 'value' => 'search'],
				]]],
			]],
		], JSON_THROW_ON_ERROR));
		$registry = new FirewallConditionRegistry([new RequestMatchCondition()]);
		$result = (new FirewallRuleEvaluator(new FirewallRuleValidator($registry), $registry))->evaluate(
			$rules,
			new FirewallRequestContext(['uri' => [['name' => null, 'value' => '/search']]], 1, 'GET', '/search', '127.0.0.1'),
		);

		self::assertCount(1, $result->findings);
	}

	public function testConvertsSupportedSecLangRuleChainsAsAndGroups(): void
	{
		$rules = $this->createConverter()->convert('modsecurity-seclang', <<<'SECLANG'
SecRule ARGS "@rx union\s+select" "id:942100,phase:2,deny,msg:'SQL injection',chain,t:none"
 SecRule REQUEST_HEADERS:User-Agent "@contains badbot" "t:lowercase"
SECLANG);

		self::assertCount(1, $rules);
		self::assertSame('seclang:942100', $rules[0]['id']);
		self::assertSame('deny', $rules[0]['action']);
		self::assertArrayHasKey('all', $rules[0]['conditions']);
		self::assertCount(2, $rules[0]['conditions']['all']);

		$registry = new FirewallConditionRegistry([new RequestMatchCondition()]);
		$result = (new FirewallRuleEvaluator(new FirewallRuleValidator($registry), $registry))->evaluate(
			$rules,
			new FirewallRequestContext(
				[
					'query' => [['name' => 'q', 'value' => 'union select password']],
					'header' => [['name' => 'User-Agent', 'value' => 'BadBot scanner']],
				],
				1,
				'GET',
				'/search',
				'127.0.0.1',
			),
		);
		self::assertCount(1, $result->findings);
	}

	public function testRejectsUnsupportedSecLangDirectiveInsteadOfSkippingIt(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->createConverter()->convert('modsecurity-seclang', "SecAction \"id:1,phase:1,pass\"\n");
	}

	public function testRejectsUnsupportedSecLangAction(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->createConverter()->convert('modsecurity-seclang', 'SecRule REQUEST_URI "@contains /admin" "id:9,phase:1,setvar:tx.score=+5"');
	}

	private function createConverter(): FirewallRulePackConverter
	{
		return new FirewallRulePackConverter(new FirewallRuleValidator(
			new FirewallConditionRegistry([new RequestMatchCondition()]),
		));
	}
}
