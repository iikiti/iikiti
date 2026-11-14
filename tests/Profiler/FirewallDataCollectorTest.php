<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Profiler;

use iikiti\CMS\Profiler\FirewallDataCollector;
use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\FirewallFinding;
use iikiti\CMS\Security\Firewall\FirewallSecurityEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FirewallDataCollectorTest extends TestCase
{
	public function testSnapshotsRuleMetadataWithoutMatchedValuesOrConfiguredLiterals(): void
	{
		$collector = new FirewallDataCollector();
		$secretValue = 'sensitive-request-value';
		$secretLiteral = 'sensitive-rule-literal';
		$unsafeConditions = json_decode(
			'[{"condition":"request.match","target":"header","name":"X-Key","operator":"contains","value":"'.$secretValue.'","literal":"'.$secretLiteral.'"}]',
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$collector->onFirewallSecurityEvent(new FirewallSecurityEvent(
			[new FirewallFinding(
				'core:test',
				'Suspicious request',
				FirewallAction::Deny,
				12,
				$unsafeConditions,
			)],
			FirewallAction::Deny,
			['method' => 'POST', 'path' => '/api/items', 'siteId' => null, 'clientIp' => '127.0.0.1'],
		));
		$collector->collect(Request::create('/api/items', 'POST'), new Response('', Response::HTTP_FORBIDDEN));

		self::assertTrue($collector->isEvaluated());
		self::assertSame('blocked', $collector->getOutcome());
		self::assertSame(Response::HTTP_FORBIDDEN, $collector->getResponseStatus());
		self::assertSame(null, $collector->getRequestSummary()['site_id']);
		self::assertSame([[
			'rule_id' => 'core:test',
			'name' => 'Suspicious request',
			'action' => 'deny',
			'priority' => 12,
			'matched_conditions' => [['condition' => 'request.match', 'target' => 'header', 'name' => 'X-Key', 'operator' => 'contains']],
			'conditions_truncated' => false,
		]], $collector->getFindings());
		$serialized = serialize($collector);
		self::assertStringNotContainsString($secretValue, $serialized);
		self::assertStringNotContainsString($secretLiteral, $serialized);
		self::assertStringContainsString('core:test', $serialized);
		self::assertArrayNotHasKey('value', $collector->getFindings()[0]['matched_conditions'][0]);
		self::assertArrayNotHasKey('literal', $collector->getFindings()[0]['matched_conditions'][0]);
	}

	public function testNoMatchAndNotEvaluatedStatesAreDistinctAndResettable(): void
	{
		$collector = new FirewallDataCollector();
		$collector->onFirewallSecurityEvent(new FirewallSecurityEvent(
			[],
			FirewallAction::Report,
			['method' => 'GET', 'path' => '/api/items', 'siteId' => 4, 'clientIp' => '127.0.0.1'],
		));
		$collector->collect(Request::create('/api/items'), new Response());
		self::assertSame('no_match', $collector->getOutcome());

		$collector->reset();
		$collector->collect(Request::create('/outside'), new Response());

		self::assertFalse($collector->isEvaluated());
		self::assertSame('not_evaluated', $collector->getOutcome());
		self::assertNull($collector->getRequestSummary());
		self::assertSame([], $collector->getFindings());
	}

	public function testCapsProfilerFindingsAndMatchedConditions(): void
	{
		$collector = new FirewallDataCollector();
		$conditions = array_fill(0, 65, ['condition' => 'request.match', 'target' => 'path', 'operator' => 'contains']);
		$findings = [];
		for ($index = 0; $index < 101; ++$index) {
			$findings[] = new FirewallFinding('rule:'.$index, 'Rule '.$index, FirewallAction::Report, 0, $conditions);
		}
		$collector->onFirewallSecurityEvent(new FirewallSecurityEvent(
			$findings,
			FirewallAction::Report,
			['method' => 'GET', 'path' => '/', 'siteId' => 1, 'clientIp' => '127.0.0.1'],
		));

		self::assertCount(100, $collector->getFindings());
		self::assertCount(64, $collector->getFindings()[0]['matched_conditions']);
		self::assertTrue($collector->isFindingsTruncated());
	}

	public function testCapturesBoundedStageOneMetadataWithoutRawRequestValues(): void
	{
		$collector = new FirewallDataCollector();
		$secretHeader = 'Bearer super-secret-token';
		$collector->onFirewallSecurityEvent(new FirewallSecurityEvent(
			[new FirewallFinding('bot:impersonation', 'Client claims Googlebot.', FirewallAction::Report)],
			FirewallAction::Deny,
			['method' => 'GET', 'path' => '/', 'siteId' => 2, 'clientIp' => '203.0.113.9'],
			[
				'ip_action' => 'none',
				'bot' => ['action' => 'block', 'category' => 'search', 'botId' => 'googlebot', 'verified' => false, 'impersonated' => true, 'bypassWaf' => false, 'rawHeader' => $secretHeader],
				'rate_limit' => ['rule' => 'core:rl-public-api', 'accepted' => true, 'remaining' => 3],
				'dos_status' => 'allowed',
				'bypass_waf' => false,
				'denied_by' => 'bot_block',
				'cookies' => ['session' => $secretHeader],
			],
		));
		$collector->collect(Request::create('/'), new Response('', Response::HTTP_FORBIDDEN));

		$stage = $collector->getStage();
		self::assertSame('none', $stage['ip_action']);
		self::assertSame('block', $stage['bot']['action']);
		self::assertSame('googlebot', $stage['bot']['botId']);
		self::assertTrue($stage['bot']['impersonated']);
		self::assertSame('core:rl-public-api', $stage['rate_limit']['rule']);
		self::assertTrue($stage['rate_limit']['accepted']);
		self::assertSame('bot_block', $stage['denied_by']);
		self::assertSame('allowed', $stage['dos_status']);
		self::assertFalse($stage['bypass_waf']);

		$serialized = serialize($collector);
		self::assertStringNotContainsString($secretHeader, $serialized);
		self::assertArrayNotHasKey('rawHeader', $stage['bot']);
		self::assertArrayNotHasKey('remaining', $stage['rate_limit']);
		self::assertArrayNotHasKey('cookies', $stage);
	}

	public function testStageMetadataDefaultsWhenEventCarriesNone(): void
	{
		$collector = new FirewallDataCollector();
		$collector->onFirewallSecurityEvent(new FirewallSecurityEvent(
			[],
			FirewallAction::Report,
			['method' => 'GET', 'path' => '/', 'siteId' => null, 'clientIp' => '127.0.0.1'],
		));
		$collector->collect(Request::create('/'), new Response());

		$stage = $collector->getStage();
		self::assertSame('none', $stage['ip_action']);
		self::assertNull($stage['bot']);
		self::assertNull($stage['rate_limit']);
		self::assertSame('allowed', $stage['dos_status']);
		self::assertFalse($stage['bypass_waf']);
		self::assertNull($stage['denied_by']);
	}
}
