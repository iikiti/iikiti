<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use iikiti\CMS\Entity\FirewallIpBan;
use iikiti\CMS\Repository\FirewallIpBanRepository;
use iikiti\CMS\Repository\Object\FirewallIpRuleRepository;
use iikiti\CMS\Security\Firewall\Dos\FirewallAnomalyDetector;
use iikiti\CMS\Security\Firewall\Dos\FirewallAutoBanManager;
use iikiti\CMS\Security\Firewall\Dos\FirewallDosGuard;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use iikiti\CMS\Security\Firewall\Policy\CoreProtectionPolicyDefaults;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

#[AllowMockObjectsWithoutExpectations]
final class FirewallDosAndAutoBanTest extends TestCase
{
	private ArrayAdapter $cache;

	protected function setUp(): void
	{
		$this->cache = new ArrayAdapter();
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function dos(array $overrides = []): array
	{
		return array_replace(CoreProtectionPolicyDefaults::dos(), $overrides);
	}

	public function testAcceptsWellFormedGetRequest(): void
	{
		$result = (new FirewallDosGuard())->inspect(Request::create('/home?x=1'), $this->dos(), 'public');

		self::assertFalse($result->blocked);
	}

	public function testRejectsOversizedUriWithBadRequest(): void
	{
		$request = Request::create('/'.str_repeat('a', 200));

		$result = (new FirewallDosGuard())->inspect($request, $this->dos(['maxUriLength' => 50]), 'public');

		self::assertTrue($result->blocked);
		self::assertSame(400, $result->statusCode);
		self::assertContains('dos:uri-length', $result->findings);
	}

	public function testRejectsTooManyQueryParams(): void
	{
		$request = Request::create('/?'.http_build_query(array_fill_keys(range('a', 'e'), '1')));

		$result = (new FirewallDosGuard())->inspect($request, $this->dos(['maxQueryParams' => 2]), 'public');

		self::assertContains('dos:query-params', $result->findings);
	}

	public function testRejectsTooManyCookies(): void
	{
		$request = Request::create('/', 'GET', [], ['a' => '1', 'b' => '2', 'c' => '3']);

		$result = (new FirewallDosGuard())->inspect($request, $this->dos(['maxCookieCount' => 2]), 'public');

		self::assertContains('dos:cookie-count', $result->findings);
	}

	public function testRejectsUnexpectedBodyOnGet(): void
	{
		$request = Request::create('/home', 'GET', [], [], [], ['CONTENT_LENGTH' => '5'], 'hello');

		$result = (new FirewallDosGuard())->inspect($request, $this->dos(), 'public');

		self::assertContains('dos:unexpected-body', $result->findings);
		self::assertSame(400, $result->statusCode);
	}

	public function testRejectsBodyOverRouteGroupLimitWithPayloadTooLarge(): void
	{
		$request = Request::create('/api/auth/login', 'POST', [], [], [], ['CONTENT_LENGTH' => '70000'], str_repeat('x', 70000));

		$result = (new FirewallDosGuard())->inspect($request, $this->dos(), 'auth');

		self::assertTrue($result->blocked);
		self::assertSame(413, $result->statusCode);
		self::assertContains('dos:body-size', $result->findings);
	}

	public function testDisabledGuardAllowsEverything(): void
	{
		$request = Request::create('/'.str_repeat('a', 300));

		self::assertFalse((new FirewallDosGuard())->inspect($request, $this->dos(['enabled' => false, 'maxUriLength' => 1]), 'public')->blocked);
	}

	public function testFourXxStormDetectedAfterThreshold(): void
	{
		$detector = new FirewallAnomalyDetector($this->cache, new NullLogger());
		$config = $this->dos(['errorStormThreshold' => 3, 'errorStormWindow' => 60]);

		$results = [];
		for ($i = 0; $i < 4; ++$i) {
			$results[] = $detector->recordResponseStatus('192.0.2.10', 404, $config, 1);
		}

		self::assertSame([false, false, false, true], $results);
	}

	public function testSuccessfulResponsesDoNotCountTowardStorm(): void
	{
		$detector = new FirewallAnomalyDetector($this->cache, new NullLogger());

		self::assertFalse($detector->recordResponseStatus('192.0.2.10', 200, $this->dos(['errorStormThreshold' => 0]), 1));
	}

	public function testConcurrencyAcquireAndReleaseBoundsPerIp(): void
	{
		$detector = new FirewallAnomalyDetector($this->cache, new NullLogger());
		$config = $this->dos(['expensiveRouteConcurrencyPerIp' => 2, 'expensiveRouteConcurrencyPerSite' => 100]);

		$first = $detector->acquireConcurrencySlot('192.0.2.10', 'search', $config, 1);
		$second = $detector->acquireConcurrencySlot('192.0.2.10', 'search', $config, 1);
		$third = $detector->acquireConcurrencySlot('192.0.2.10', 'search', $config, 1);

		self::assertTrue($first->acquired);
		self::assertTrue($second->acquired);
		self::assertFalse($third->acquired, 'Third concurrent slot for one IP must be refused.');
		self::assertTrue($third->exceeded);

		$detector->releaseConcurrencySlot((string) $first->slotKey);
		self::assertTrue($detector->acquireConcurrencySlot('192.0.2.10', 'search', $config, 1)->acquired);
	}

	public function testConcurrencyBoundsPerSite(): void
	{
		$detector = new FirewallAnomalyDetector($this->cache, new NullLogger());
		$config = $this->dos(['expensiveRouteConcurrencyPerIp' => 10, 'expensiveRouteConcurrencyPerSite' => 1]);

		self::assertTrue($detector->acquireConcurrencySlot('192.0.2.10', 'admin', $config, 7)->acquired);
		$refused = $detector->acquireConcurrencySlot('198.51.100.9', 'admin', $config, 7);
		self::assertFalse($refused->acquired);
		self::assertTrue($refused->exceeded);
	}

	public function testCheapRoutesDoNotAcquireSlots(): void
	{
		$detector = new FirewallAnomalyDetector($this->cache, new NullLogger());
		$result = $detector->acquireConcurrencySlot('192.0.2.10', 'auth', $this->dos(), 1);

		self::assertFalse($result->acquired);
		self::assertFalse($result->exceeded);
		self::assertNull($result->slotKey);
	}

	public function testStrikesBelowThresholdDoNotBan(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->expects(self::never())->method('findActiveOneByCidr');
		$manager = $this->manager($repository);
		$config = $this->dos(['autoBanStrikeThreshold' => 3, 'autoBanAction' => 'enforce']);

		self::assertNull($manager->recordStrike('192.0.2.10', 'waf', 'bad', $config, 1));
		self::assertNull($manager->recordStrike('192.0.2.10', 'waf', 'bad', $config, 1));
	}

	public function testReportModeNeverCreatesBanEvenAtThreshold(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->expects(self::never())->method('findActiveOneByCidr');
		$manager = $this->manager($repository);
		$config = $this->dos(['autoBanStrikeThreshold' => 1, 'autoBanAction' => 'report']);

		self::assertNull($manager->recordStrike('192.0.2.10', 'waf', 'bad', $config, 1));
	}

	public function testStrikeThresholdEnforcesBanOnSubnet(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->method('findActiveOneByCidr')->willReturn(null);
		$em = $this->entityManager([]);
		$captured = null;
		$em->expects(self::once())->method('persist')->willReturnCallback(static function (object $ban) use (&$captured): void {
			$captured = $ban;
		});
		$manager = new FirewallAutoBanManager($repository, $em, $this->ruleStore($repository), $this->cache, new NullLogger());
		$config = $this->dos(['autoBanStrikeThreshold' => 2, 'autoBanAction' => 'enforce', 'autoBanDurations' => [300, 1800]]);

		self::assertNull($manager->recordStrike('192.0.2.10', 'waf', 'bad', $config, 1));
		$ban = $manager->recordStrike('192.0.2.11', 'waf', 'bad', $config, 1);

		self::assertInstanceOf(FirewallIpBan::class, $ban);
		self::assertSame($captured, $ban);
		self::assertSame('192.0.2.0/24', $ban->getCidr(), 'Strikes aggregate per subnet, so both hosts count together.');
		self::assertSame('waf', $ban->getReasonCode());
		self::assertGreaterThan(time() + 250, $ban->getExpiresAt()?->getTimestamp() ?? 0);
	}

	public function testWeightedStrikeCanCrossThresholdInOneCall(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->method('findActiveOneByCidr')->willReturn(null);
		$manager = new FirewallAutoBanManager($repository, $this->entityManager([]), $this->ruleStore($repository), $this->cache, new NullLogger());
		$config = $this->dos(['autoBanStrikeThreshold' => 5, 'autoBanAction' => 'enforce']);

		self::assertInstanceOf(FirewallIpBan::class, $manager->recordStrike('203.0.113.5', 'bot:impersonation', 'imp', $config, 1, 5));
	}

	public function testBackoffEscalatesDurationByPriorBanCount(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->method('findActiveOneByCidr')->willReturn(null);
		$em = $this->entityManager([], priorBans: 2);
		$manager = new FirewallAutoBanManager($repository, $em, $this->ruleStore($repository), $this->cache, new NullLogger());
		$durations = [300, 1800, 21600, 86400];
		$config = $this->dos(['autoBanStrikeThreshold' => 1, 'autoBanAction' => 'enforce', 'autoBanDurations' => $durations]);

		$ban = $manager->recordStrike('198.51.100.7', 'waf', 'bad', $config, 1);

		self::assertInstanceOf(FirewallIpBan::class, $ban);
		$remaining = ($ban->getExpiresAt()?->getTimestamp() ?? 0) - time();
		self::assertGreaterThan(21000, $remaining, 'Third offence escalates to the 21600s tier.');
		self::assertLessThanOrEqual(21600, $remaining);
	}

	public function testBackoffCapsAtLargestConfiguredDuration(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->method('findActiveOneByCidr')->willReturn(null);
		$manager = new FirewallAutoBanManager($repository, $this->entityManager([], priorBans: 99), $this->ruleStore($repository), $this->cache, new NullLogger());
		$config = $this->dos(['autoBanStrikeThreshold' => 1, 'autoBanAction' => 'enforce', 'autoBanDurations' => [300, 600]]);

		$ban = $manager->recordStrike('198.51.100.8', 'waf', 'bad', $config, 1);

		self::assertInstanceOf(FirewallIpBan::class, $ban);
		self::assertLessThanOrEqual(600, ($ban->getExpiresAt()?->getTimestamp() ?? 0) - time());
	}

	public function testAutoBanDisabledNeverBans(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->expects(self::never())->method('findActiveOneByCidr');
		$manager = $this->manager($repository);

		self::assertNull($manager->recordStrike('192.0.2.10', 'waf', 'bad', $this->dos(['autoBanEnabled' => false, 'autoBanAction' => 'enforce']), 1));
	}

	public function testBanExtendsExistingActiveBanInsteadOfDuplicating(): void
	{
		$existing = new FirewallIpBan();
		$existing->setCidr('192.0.2.0/24');
		$existing->setExpiresAt((new \DateTimeImmutable())->modify('+60 seconds'));
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->method('findActiveOneByCidr')->willReturn($existing);
		$em = $this->entityManager([]);
		$em->expects(self::once())->method('persist')->with($existing);
		$manager = new FirewallAutoBanManager($repository, $em, $this->ruleStore($repository), $this->cache, new NullLogger());

		$ban = $manager->ban('192.0.2.77', 'manual', 'operator', 3600);

		self::assertSame($existing, $ban);
		self::assertGreaterThan(time() + 3000, $ban->getExpiresAt()?->getTimestamp() ?? 0, 'Longer ban wins.');
	}

	public function testPermanentBanHasNoExpiry(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->method('findActiveOneByCidr')->willReturn(null);
		$manager = $this->manager($repository);

		self::assertNull($manager->ban('203.0.113.9', 'manual', 'forever', null)->getExpiresAt());
	}

	public function testUnbanRemovesBanAndInvalidatesCache(): void
	{
		$ban = new FirewallIpBan();
		$ban->setCidr('192.0.2.5');
		$ban->setSiteId(0);
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->method('find')->willReturnCallback(static fn (mixed $id): ?FirewallIpBan => 42 === $id ? $ban : null);
		$em = $this->entityManager([]);
		$em->expects(self::once())->method('remove')->with($ban);
		$store = $this->ruleStore($repository);
		$manager = new FirewallAutoBanManager($repository, $em, $store, $this->cache, new NullLogger());

		self::assertTrue($manager->unban(42));
	}

	public function testUnbanOfUnknownIdReturnsFalse(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->method('find')->willReturn(null);
		$manager = $this->manager($repository);

		self::assertFalse($manager->unban(999));
	}

	public function testUnbanByIpRemovesMatchingBansAcrossScopes(): void
	{
		$first = new FirewallIpBan();
		$first->setCidr('192.0.2.0/24');
		$second = new FirewallIpBan();
		$second->setCidr('192.0.2.0/24');
		$second->setSiteId(5);
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$em = $this->entityManager([$first, $second]);
		$em->expects(self::exactly(2))->method('remove');
		$manager = new FirewallAutoBanManager($repository, $em, $this->ruleStore($repository), $this->cache, new NullLogger());

		self::assertSame(2, $manager->unbanByIpOrCidr('192.0.2.77', null));
	}

	public function testRuleStoreCacheIsInvalidatedOnBanChange(): void
	{
		$repository = $this->createMock(FirewallIpBanRepository::class);
		$repository->method('findActiveOneByCidr')->willReturn(null);
		$store = $this->ruleStore($repository);
		$matcherItem = $this->cache->getItem('firewall_ip_matcher_v1_'.hash('sha256', '5'));
		$matcherItem->set('stale');
		$this->cache->save($matcherItem);
		$manager = new FirewallAutoBanManager($repository, $this->entityManager([]), $store, $this->cache, new NullLogger());

		$manager->ban('192.0.2.1', 'manual', 'x', 60, 5);

		self::assertFalse($this->cache->hasItem('firewall_ip_matcher_v1_'.hash('sha256', '5')));
	}

	private function manager(FirewallIpBanRepository $repository): FirewallAutoBanManager
	{
		return new FirewallAutoBanManager($repository, $this->entityManager([]), $this->ruleStore($repository), $this->cache, new NullLogger());
	}

	private function ruleStore(FirewallIpBanRepository $repository): FirewallIpRuleStore
	{
		$rules = $this->createMock(FirewallIpRuleRepository::class);
		$rules->method('findForSiteAndGlobal')->willReturn([]);

		return new FirewallIpRuleStore($rules, $this->cache, new NullLogger(), [], 300);
	}

	/**
	 * Builds an EntityManager double whose query builder returns the given bans and prior-ban count.
	 *
	 * @param list<FirewallIpBan> $bans
	 *
	 * @return EntityManagerInterface&MockObject
	 */
	private function entityManager(array $bans, int $priorBans = 0): EntityManagerInterface
	{
		$query = $this->createMock(\Doctrine\ORM\Query::class);
		$query->method('getResult')->willReturn($bans);
		$query->method('getSingleScalarResult')->willReturn($priorBans);

		$qb = $this->createMock(QueryBuilder::class);
		foreach (['select', 'from', 'andWhere', 'setParameter'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('getQuery')->willReturn($query);

		$em = $this->createMock(EntityManagerInterface::class);
		$em->method('createQueryBuilder')->willReturn($qb);

		return $em;
	}
}
