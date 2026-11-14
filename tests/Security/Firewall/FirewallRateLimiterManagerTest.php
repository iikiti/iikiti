<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FirewallRateLimiterManagerTest extends TestCase
{
	private ArrayAdapter $cache;
	private FirewallRateLimiterManager $manager;

	protected function setUp(): void
	{
		$this->cache = new ArrayAdapter();
		$this->manager = new FirewallRateLimiterManager($this->cache);
	}

	public function testSlidingWindowAcceptsUpToLimitThenRejects(): void
	{
		$rules = [$this->rule('sw', ['limit' => 2])];
		$context = $this->context();

		$first = $this->manager->evaluate($rules, 'pre_auth', $context);
		$this->manager->evaluate($rules, 'pre_auth', $context);
		$third = $this->manager->evaluate($rules, 'pre_auth', $context);

		self::assertTrue($first[0]->accepted);
		self::assertFalse($third[0]->accepted);
		self::assertGreaterThan(0, $third[0]->retryAfter);
	}

	public function testFixedWindowRejectsAfterLimit(): void
	{
		$rules = [$this->rule('fw', ['algorithm' => 'fixed_window', 'limit' => 1])];
		$context = $this->context();

		$this->manager->evaluate($rules, 'pre_auth', $context);
		$second = $this->manager->evaluate($rules, 'pre_auth', $context);

		self::assertFalse($second[0]->accepted);
		self::assertSame(1, $second[0]->limit);
	}

	public function testTokenBucketUsesBurstAsCapacity(): void
	{
		$rules = [$this->rule('tb', ['algorithm' => 'token_bucket', 'limit' => 10, 'burst' => 3])];
		$context = $this->context();

		$decisions = [];
		for ($i = 0; $i < 4; ++$i) {
			$decisions[] = $this->manager->evaluate($rules, 'pre_auth', $context)[0];
		}

		self::assertTrue($decisions[0]->accepted);
		self::assertFalse($decisions[3]->accepted, 'Fourth request exceeds the burst capacity of 3.');
		self::assertSame(3, $decisions[0]->limit);
	}

	public function testSubnetKeyingSharesBucketAcrossHostsInSameSlash24(): void
	{
		$rules = [$this->rule('sn', ['keyBy' => 'subnet', 'limit' => 1])];

		$first = $this->manager->evaluate($rules, 'pre_auth', $this->context('192.0.2.10'));
		$second = $this->manager->evaluate($rules, 'pre_auth', $this->context('192.0.2.200'));
		$otherSubnet = $this->manager->evaluate($rules, 'pre_auth', $this->context('192.0.3.1'));

		self::assertTrue($first[0]->accepted);
		self::assertFalse($second[0]->accepted);
		self::assertTrue($otherSubnet[0]->accepted);
	}

	public function testIpKeyingIsolatesClients(): void
	{
		$rules = [$this->rule('ip', ['limit' => 1])];

		$this->manager->evaluate($rules, 'pre_auth', $this->context('192.0.2.10'));
		$other = $this->manager->evaluate($rules, 'pre_auth', $this->context('192.0.2.11'));

		self::assertTrue($other[0]->accepted);
	}

	public function testPreAuthPhaseSkipsUserKeyedRules(): void
	{
		$rules = [$this->rule('user', ['keyBy' => 'user', 'limit' => 1])];

		self::assertSame([], $this->manager->evaluate($rules, 'pre_auth', $this->context()));
	}

	public function testPostAuthPhaseEvaluatesUserKeyedRulesWithIdentity(): void
	{
		$rules = [$this->rule('user', ['keyBy' => 'user', 'limit' => 1])];
		$context = $this->context(authenticated: true, userIdentifier: 'alice');

		$first = $this->manager->evaluate($rules, 'post_auth', $context);
		$second = $this->manager->evaluate($rules, 'post_auth', $context);

		self::assertCount(1, $first);
		self::assertFalse($second[0]->accepted);
	}

	public function testRouteAndAuthMatchingSkipsNonMatchingRequests(): void
	{
		$rules = [$this->rule('auth', ['limit' => 1, 'routeGroup' => 'auth', 'pathPrefix' => '/api/auth/login', 'methods' => ['POST']])];

		self::assertSame([], $this->manager->evaluate($rules, 'pre_auth', $this->context(routeGroup: 'public', path: '/home')));
		self::assertSame([], $this->manager->evaluate($rules, 'pre_auth', $this->context(method: 'GET', routeGroup: 'auth', path: '/api/auth/login')));
		self::assertCount(1, $this->manager->evaluate($rules, 'pre_auth', $this->context(routeGroup: 'auth', path: '/api/auth/login')));
	}

	public function testReportActionDoesNotStopEvaluationButThrottleDoes(): void
	{
		$report = $this->rule('report', ['limit' => 1, 'action' => 'report']);
		$throttle = $this->rule('throttle', ['limit' => 1, 'action' => 'throttle']);
		$context = $this->context();

		$this->manager->evaluate([$report, $throttle], 'pre_auth', $context);
		$decisions = $this->manager->evaluate([$report, $throttle], 'pre_auth', $context);

		self::assertCount(2, $decisions, 'A rejected report rule must not short-circuit later rules.');
		self::assertSame('report', $decisions[0]->action);
		self::assertSame('throttle', $decisions[1]->action);
	}

	public function testThrottleRejectionShortCircuitsLaterRules(): void
	{
		$throttle = $this->rule('throttle', ['limit' => 1, 'action' => 'throttle']);
		$later = $this->rule('later', ['limit' => 100]);
		$context = $this->context();

		$this->manager->evaluate([$throttle, $later], 'pre_auth', $context);
		$decisions = $this->manager->evaluate([$throttle, $later], 'pre_auth', $context);

		self::assertCount(1, $decisions);
		self::assertFalse($decisions[0]->accepted);
	}

	public function testDryRunSimulationDoesNotConsumeTokens(): void
	{
		$rules = [$this->rule('dry', ['limit' => 1])];
		$context = $this->context();

		$this->manager->simulate($rules, 'pre_auth', $context);
		$this->manager->simulate($rules, 'pre_auth', $context);
		$real = $this->manager->evaluate($rules, 'pre_auth', $context);

		self::assertTrue($real[0]->accepted, 'Simulation must not spend the only token.');
	}

	public function testDecisionHeadersIncludeRetryAfterOnlyWhenRejected(): void
	{
		$rules = [$this->rule('hdr', ['limit' => 1, 'action' => 'throttle'])];
		$context = $this->context();

		$accepted = $this->manager->evaluate($rules, 'pre_auth', $context)[0];
		$rejected = $this->manager->evaluate($rules, 'pre_auth', $context)[0];

		self::assertArrayNotHasKey('Retry-After', $accepted->toHeaders());
		self::assertArrayHasKey('Retry-After', $rejected->toHeaders());
		self::assertSame('1', $accepted->toHeaders()['X-RateLimit-Limit']);
		self::assertSame('0', $rejected->toHeaders()['X-RateLimit-Remaining']);
		self::assertArrayHasKey('X-RateLimit-Reset', $rejected->toHeaders());
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function rule(string $id, array $overrides = []): array
	{
		$match = [
			'methods' => $overrides['methods'] ?? [],
			'pathPrefix' => $overrides['pathPrefix'] ?? '',
			'pathRegex' => null,
			'routeGroup' => $overrides['routeGroup'] ?? 'custom',
			'authenticated' => $overrides['authenticated'] ?? 'any',
		];
		unset($overrides['methods'], $overrides['pathPrefix'], $overrides['routeGroup'], $overrides['authenticated']);

		return array_replace([
			'id' => $id,
			'name' => $id,
			'enabled' => true,
			'priority' => 0,
			'inheritance' => 'default',
			'action' => 'throttle',
			'algorithm' => 'sliding_window',
			'limit' => 10,
			'interval' => 60,
			'burst' => null,
			'keyBy' => 'ip',
			'match' => $match,
		], $overrides);
	}

	private function context(
		string $ip = '192.0.2.10',
		string $method = 'POST',
		string $routeGroup = 'custom',
		string $path = '/x',
		bool $authenticated = false,
		?string $userIdentifier = null,
	): FirewallRateLimitContext {
		return new FirewallRateLimitContext(
			clientIp: $ip,
			method: $method,
			path: $path,
			routeGroup: $routeGroup,
			authenticated: $authenticated,
			userIdentifier: $userIdentifier,
		);
	}
}
