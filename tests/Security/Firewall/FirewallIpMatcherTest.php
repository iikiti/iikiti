<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpMatcher;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleSpec;
use PHPUnit\Framework\TestCase;

final class FirewallIpMatcherTest extends TestCase
{
	private const NOW = 1_800_000_000;

	public function testExactIpv4HostRuleBlocks(): void
	{
		$matcher = new FirewallIpMatcher([$this->rule('block-host', '192.0.2.10', 'block')]);

		$result = $matcher->match('192.0.2.10');

		self::assertSame('block', $result->action);
		self::assertSame('block-host', $result->matchedRule?->id);
		self::assertSame(FirewallAction::Deny, $result->findings[0]->action);
	}

	public function testIpv4CidrRuleBlocksMembersOnly(): void
	{
		$matcher = new FirewallIpMatcher([$this->rule('net', '198.51.100.0/24', 'block')]);

		self::assertSame('block', $matcher->match('198.51.100.200')->action);
		self::assertSame('none', $matcher->match('198.51.101.1')->action);
	}

	public function testIpv6ExactAndCidrRules(): void
	{
		$matcher = new FirewallIpMatcher([
			$this->rule('v6-host', '2001:db8::5', 'block'),
			$this->rule('v6-net', '2001:db8:0:1::/64', 'allow'),
		]);

		self::assertSame('block', $matcher->match('2001:db8::5')->action);
		self::assertSame('allow', $matcher->match('2001:db8:0:1:abcd::9')->action);
		self::assertSame('none', $matcher->match('2001:db8:0:2::1')->action);
	}

	public function testHostBitsInRuleAreNormalizedBeforeMatching(): void
	{
		$matcher = new FirewallIpMatcher([$this->rule('net', '192.0.2.55/24', 'block')]);

		self::assertSame('block', $matcher->match('192.0.2.200')->action);
	}

	public function testInvalidOrEmptyClientIpReturnsNone(): void
	{
		$matcher = new FirewallIpMatcher([$this->rule('any', '0.0.0.0/0', 'block')]);

		$result = $matcher->match('not-an-ip');

		self::assertSame('none', $result->action);
		self::assertNull($result->matchedRule);
		self::assertSame([], $result->findings);
	}

	public function testExpiredRulesAreIgnored(): void
	{
		$expired = new FirewallIpRuleSpec('old', '192.0.2.1/32', 'block', 'default', 'global', expiresAt: self::NOW - 1);
		$matcher = new FirewallIpMatcher([$expired]);

		self::assertSame('none', $matcher->match('192.0.2.1', self::NOW)->action);
	}

	public function testUnexpiredRuleStillMatchesBeforeExpiry(): void
	{
		$active = new FirewallIpRuleSpec('live', '192.0.2.1/32', 'block', 'default', 'global', expiresAt: self::NOW + 60);
		$matcher = new FirewallIpMatcher([$active]);

		self::assertSame('block', $matcher->match('192.0.2.1', self::NOW)->action);
	}

	public function testDisabledRulesAreIgnored(): void
	{
		$disabled = new FirewallIpRuleSpec('off', '192.0.2.1/32', 'block', 'default', 'global', enabled: false);
		$matcher = new FirewallIpMatcher([$disabled]);

		self::assertSame('none', $matcher->match('192.0.2.1')->action);
	}

	public function testGlobalRequiredOverridesSiteAllow(): void
	{
		$matcher = new FirewallIpMatcher([
			$this->rule('site-allow', '203.0.113.0/24', 'allow', 'default', 'site'),
			$this->rule('global-required-block', '203.0.113.7/32', 'block', 'required', 'global'),
		]);

		$result = $matcher->match('203.0.113.7');

		self::assertSame('block', $result->action);
		self::assertSame('global-required-block', $result->matchedRule?->id);
	}

	public function testSiteRuleOverridesGlobalDefault(): void
	{
		$matcher = new FirewallIpMatcher([
			$this->rule('global-default-block', '203.0.113.0/24', 'block', 'default', 'global'),
			$this->rule('site-allow', '203.0.113.7/32', 'allow', 'default', 'site'),
		]);

		$result = $matcher->match('203.0.113.7');

		self::assertSame('allow', $result->action);
		self::assertSame('site-allow', $result->matchedRule?->id);
	}

	public function testReportRulesAccumulateWithoutTerminating(): void
	{
		$matcher = new FirewallIpMatcher([
			$this->rule('watch-net', '192.0.2.0/24', 'report', 'default', 'global', 1, 'watching'),
			$this->rule('watch-host', '192.0.2.9/32', 'report', 'default', 'global', 2),
		]);

		$result = $matcher->match('192.0.2.9');

		self::assertSame('report', $result->action);
		self::assertNull($result->matchedRule);
		self::assertCount(2, $result->findings);
		self::assertSame('watching', $result->findings[0]->message);
		self::assertSame(FirewallAction::Report, $result->findings[0]->action);
	}

	public function testAllowStopsEvaluationAfterReportsInEarlierTier(): void
	{
		$matcher = new FirewallIpMatcher([
			$this->rule('report-global', '192.0.2.0/24', 'report', 'required', 'global'),
			$this->rule('allow-site', '192.0.2.9/32', 'allow', 'default', 'site'),
		]);

		$result = $matcher->match('192.0.2.9');

		self::assertSame('allow', $result->action);
		self::assertCount(2, $result->findings);
		self::assertSame(FirewallAction::Report, $result->findings[1]->action);
	}

	public function testPriorityOrdersRulesWithinTier(): void
	{
		$matcher = new FirewallIpMatcher([
			$this->rule('late-block', '192.0.2.0/24', 'block', 'default', 'global', 10),
			$this->rule('early-allow', '192.0.2.0/24', 'allow', 'default', 'global', 1),
		]);

		self::assertSame('early-allow', $matcher->match('192.0.2.1')->matchedRule?->id);
	}

	private function rule(
		string $id,
		string $cidr,
		string $action,
		string $inheritance = 'default',
		string $scope = 'global',
		int $priority = 0,
		string $reason = '',
	): FirewallIpRuleSpec {
		return new FirewallIpRuleSpec($id, $cidr, $action, $inheritance, $scope, $priority, true, null, $reason);
	}
}
