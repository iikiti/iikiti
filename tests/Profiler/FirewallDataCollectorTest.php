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
}
