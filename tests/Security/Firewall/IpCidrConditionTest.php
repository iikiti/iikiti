<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\Condition\IpCidrCondition;
use iikiti\CMS\Security\Firewall\FirewallConditionRegistry;
use iikiti\CMS\Security\Firewall\FirewallRequestContext;
use iikiti\CMS\Security\Firewall\FirewallRuleEvaluator;
use iikiti\CMS\Security\Firewall\FirewallRulePackConverter;
use iikiti\CMS\Security\Firewall\FirewallRuleValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpCidrConditionTest extends TestCase
{
	public function testRegistersUnderIpCidrName(): void
	{
		$registry = new FirewallConditionRegistry([new IpCidrCondition()]);

		self::assertTrue($registry->has('ip.cidr'));
		self::assertSame('ip.cidr', $registry->get('ip.cidr')->getName());
	}

	public function testValidateOptionsAcceptsStringAndListValues(): void
	{
		$condition = new IpCidrCondition();

		$condition->validateOptions(['value' => '192.0.2.0/24']);
		$condition->validateOptions(['value' => ['192.0.2.0/24', '2001:db8::/32'], 'negate' => true]);
		$this->addToAssertionCount(2);
	}

	/** @param array<string,mixed> $options */
	#[DataProvider('invalidOptionsProvider')]
	public function testValidateOptionsRejectsInvalidInput(array $options): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new IpCidrCondition())->validateOptions($options);
	}

	/** @return iterable<string,array{array<string,mixed>}> */
	public static function invalidOptionsProvider(): iterable
	{
		yield 'missing value' => [[]];
		yield 'empty string' => [['value' => '']];
		yield 'empty list' => [['value' => []]];
		yield 'unknown key' => [['value' => '192.0.2.0/24', 'extra' => 1]];
		yield 'bad cidr' => [['value' => '192.0.2.0/99']];
		yield 'bad entry in list' => [['value' => ['192.0.2.0/24', 'nope']]];
		yield 'non-boolean negate' => [['value' => '192.0.2.0/24', 'negate' => 'yes']];
		yield 'too many values' => [['value' => array_fill(0, 257, '192.0.2.1')]];
		yield 'non-list value' => [['value' => ['a' => '192.0.2.1']]];
	}

	public function testCouldMatchRequestRequiresIpValues(): void
	{
		$condition = new IpCidrCondition();
		$context = new FirewallRequestContext([], 1, 'GET', '/', '192.0.2.1');

		self::assertTrue($condition->couldMatchRequest(['value' => '192.0.2.0/24'], $context));
		self::assertSame([FirewallRequestContext::indexKey('ip')], $condition->getRequestIndexKeys(['value' => '192.0.2.0/24']));
	}

	public function testMatchesClientIpAgainstAllRanges(): void
	{
		$condition = new IpCidrCondition();
		$context = new FirewallRequestContext([], 1, 'GET', '/', '2001:db8:0:1::42');

		self::assertTrue($condition->matches(['value' => ['192.0.2.0/24', '2001:db8:0:1::/64']], $context));
		self::assertFalse($condition->matches(['value' => '198.51.100.0/24'], $context));
	}

	public function testNegateInvertsTheMatch(): void
	{
		$condition = new IpCidrCondition();
		$context = new FirewallRequestContext([], 1, 'GET', '/', '192.0.2.10');

		self::assertFalse($condition->matches(['value' => '192.0.2.0/24', 'negate' => true], $context));
		self::assertTrue($condition->matches(['value' => '198.51.100.0/24', 'negate' => true], $context));
	}

	public function testSecLangIpMatchConvertsToIpCidrCondition(): void
	{
		$rules = (new FirewallRulePackConverter(new FirewallRuleValidator($this->registry())))->convert(
			'modsecurity-seclang',
			'SecRule REMOTE_ADDR "@ipMatch 192.0.2.0/24, 2001:db8::/32" "id:900001,phase:1,deny,msg:\'Blocked range\'"',
		);

		self::assertCount(1, $rules);
		self::assertSame('seclang:900001', $rules[0]['id']);
		self::assertSame('deny', $rules[0]['action']);
		self::assertSame('ip.cidr', $rules[0]['conditions']['condition']);
		self::assertSame(['192.0.2.0/24', '2001:db8::/32'], $rules[0]['conditions']['options']['value']);
	}

	public function testSecLangIpMatchOnlyAllowedForRemoteAddr(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new FirewallRulePackConverter(new FirewallRuleValidator($this->registry())))->convert(
			'modsecurity-seclang',
			'SecRule REQUEST_URI "@ipMatch 192.0.2.0/24" "id:900002,phase:1,deny"',
		);
	}

	public function testSecLangIpMatchFromFileRemainsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new FirewallRulePackConverter(new FirewallRuleValidator($this->registry())))->convert(
			'modsecurity-seclang',
			'SecRule REMOTE_ADDR "@ipMatchFromFile /etc/blocklist.txt" "id:900003,phase:1,deny"',
		);
	}

	public function testConvertedIpRuleEvaluatesThroughEvaluator(): void
	{
		$registry = $this->registry();
		$rules = (new FirewallRulePackConverter(new FirewallRuleValidator($registry)))->convert(
			'modsecurity-seclang',
			'SecRule REMOTE_ADDR "@ipMatch 192.0.2.0/24" "id:900004,phase:1,deny,msg:\'Blocked\'"',
		);

		$result = (new FirewallRuleEvaluator(new FirewallRuleValidator($registry), $registry))->evaluate(
			$rules,
			new FirewallRequestContext([], 1, 'GET', '/', '192.0.2.77'),
		);

		self::assertCount(1, $result->findings);
	}

	private function registry(): FirewallConditionRegistry
	{
		return new FirewallConditionRegistry([new \iikiti\CMS\Security\Firewall\Condition\RequestMatchCondition(), new IpCidrCondition()]);
	}
}
