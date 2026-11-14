<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitContext;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Proves the dry-run guarantee the simulator depends on: evaluating a rate-limit rule in simulate
 * mode must never spend tokens, while a live evaluation must.
 */
final class FirewallSimulationTest extends TestCase
{
	private const CLIENT_IP = '198.51.100.9';

	public function testSimulationDoesNotConsumeLiveRateLimitTokens(): void
	{
		$manager = new FirewallRateLimiterManager(new ArrayAdapter());
		$rules = [$this->rule(limit: 2)];

		// Simulating far beyond the limit must never reject, because it spends nothing.
		for ($i = 0; $i < 25; ++$i) {
			$decisions = $manager->simulate($rules, 'pre_auth', $this->context());
			self::assertTrue($decisions[0]->accepted, 'Simulation must not exhaust the live budget.');
		}

		// The live budget is untouched: the first live request still gets the full allowance.
		$live = $manager->evaluate($rules, 'pre_auth', $this->context());
		self::assertTrue($live[0]->accepted);
		self::assertSame(1, $live[0]->remaining, 'A live request should see the full budget minus its own single token.');
	}

	public function testLiveEvaluationStillConsumesTokensAfterSimulation(): void
	{
		$manager = new FirewallRateLimiterManager(new ArrayAdapter());
		$rules = [$this->rule(limit: 2)];

		$manager->simulate($rules, 'pre_auth', $this->context());
		$manager->simulate($rules, 'pre_auth', $this->context());

		$first = $manager->evaluate($rules, 'pre_auth', $this->context());
		$second = $manager->evaluate($rules, 'pre_auth', $this->context());
		$third = $manager->evaluate($rules, 'pre_auth', $this->context());

		self::assertTrue($first[0]->accepted);
		self::assertTrue($second[0]->accepted);
		self::assertFalse($third[0]->accepted, 'Live requests must still be throttled once the real budget is spent.');
	}

	public function testSimulatedDecisionReportsTheSameLimitAsLive(): void
	{
		$manager = new FirewallRateLimiterManager(new ArrayAdapter());
		$rules = [$this->rule(limit: 5)];

		$simulated = $manager->simulate($rules, 'pre_auth', $this->context());

		self::assertSame(5, $simulated[0]->limit);
		self::assertSame('throttle', $simulated[0]->action);
	}

	/** @return array<string,mixed> */
	private function rule(int $limit): array
	{
		return [
			'id' => 'core:sim-test',
			'name' => 'Simulation test rule',
			'enabled' => true,
			'priority' => 0,
			'inheritance' => 'default',
			'action' => 'throttle',
			'algorithm' => 'fixed_window',
			'limit' => $limit,
			'interval' => 60,
			'keyBy' => 'ip',
			'match' => ['methods' => [], 'pathPrefix' => '', 'pathRegex' => null, 'routeGroup' => 'custom', 'authenticated' => 'any'],
		];
	}

	private function context(): FirewallRateLimitContext
	{
		return new FirewallRateLimitContext(
			clientIp: self::CLIENT_IP,
			method: 'GET',
			path: '/api/items',
			routeGroup: 'api',
			authenticated: false,
		);
	}
}
