<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Repository\Object\FirewallIpRuleRepository;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeManager;
use iikiti\CMS\Security\Firewall\CoreFirewallRules;
use iikiti\CMS\Security\Firewall\Dos\FirewallAnomalyDetector;
use iikiti\CMS\Security\Firewall\Dos\FirewallAutoBanManager;
use iikiti\CMS\Security\Firewall\Dos\FirewallDosGuard;
use iikiti\CMS\Security\Firewall\FirewallConditionRegistry;
use iikiti\CMS\Security\Firewall\FirewallFeedbackSubscriber;
use iikiti\CMS\Security\Firewall\FirewallRequestContextFactory;
use iikiti\CMS\Security\Firewall\FirewallRequestSubscriber;
use iikiti\CMS\Security\Firewall\FirewallRuleEvaluator;
use iikiti\CMS\Security\Firewall\FirewallRulePackSourceRegistry;
use iikiti\CMS\Security\Firewall\FirewallRulePackStore;
use iikiti\CMS\Security\Firewall\FirewallRuleSetCache;
use iikiti\CMS\Security\Firewall\FirewallRuleSetResolver;
use iikiti\CMS\Security\Firewall\FirewallRuleValidator;
use iikiti\CMS\Security\Firewall\FirewallSecurityEvent;
use iikiti\CMS\Security\Firewall\FirewallSitePolicyStore;
use iikiti\CMS\Security\Firewall\FirewallStageOneServices;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpMatcher;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleSpec;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyResolverInterface;
use iikiti\CMS\Security\Firewall\Policy\FirewallResolvedPolicy;
use iikiti\CMS\Security\Firewall\Policy\CoreProtectionPolicyDefaults;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRouteClassifier;
use iikiti\CMS\Security\Firewall\Condition\RequestMatchCondition;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\FirewallTrustedAutomationRepository;
use iikiti\CMS\Repository\FirewallIpBanRepository;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotDetector;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotCatalog;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotVerifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit-level pipeline checks: Stage-1 gating order, short-circuits that never read the body,
 * rate-limit and concurrency feedback, and the 4xx storm signal.
 */
final class FirewallPipelineIntegrationTest extends TestCase
{
	/** Counters and strikes live in `cache.firewall_ratelimit`; IP matcher state lives in `cache.firewall`. */
	private ArrayAdapter $cache;
	private ArrayAdapter $matcherCache;
	private int $persistedBanCount = 0;

	protected function setUp(): void
	{
		parent::setUp();
		$this->cache = new ArrayAdapter();
		$this->matcherCache = new ArrayAdapter();
		$this->clearCurrentSite();
	}

	protected function tearDown(): void
	{
		$this->clearCurrentSite();
		parent::tearDown();
	}

	public function testIpBlockShortCircuitsBeforeBodyIsRead(): void
	{
		$this->seedIpMatcher(new FirewallIpMatcher([$this->ipRule('block-attacker', '198.51.100.7/32', 'block')]));
		$request = Request::create('/api/forms/submit', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"payload":"should-not-be-read"}');
		$request->server->set('REMOTE_ADDR', '198.51.100.7');

		[$event, $securityEvents] = $this->runPipeline($request);

		$response = $event->getResponse();
		self::assertInstanceOf(Response::class, $response);
		self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
		self::assertSame('Request blocked by security policy.', json_decode((string) $response->getContent(), true)['error']);
		self::assertCount(1, $securityEvents);
		self::assertSame('ip_block', $securityEvents[0]->stageMetadata['denied_by']);
		self::assertSame('block', $securityEvents[0]->stageMetadata['ip_action']);
		self::assertFalse($request->attributes->has(FirewallRequestSubscriber::CONTEXT_ATTRIBUTE));
	}

	public function testStructuralDosRejectsOversizedHeadersWithPayloadTooLarge(): void
	{
		$this->seedIpMatcher(new FirewallIpMatcher());
		$request = Request::create('/api/items', 'POST', [], [], [], ['CONTENT_LENGTH' => '999999999', 'CONTENT_TYPE' => 'application/json'], '{}');

		[$event, $securityEvents] = $this->runPipeline($request, $this->policy(['dos' => ['maxBodyBytesByGroup' => ['api' => 10]]]));

		self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $event->getResponse()?->getStatusCode());
		self::assertSame('dos_block', $securityEvents[0]->stageMetadata['denied_by']);
		self::assertSame('blocked', $securityEvents[0]->stageMetadata['dos_status']);
	}

	public function testStructuralDosRejectsOversizedUriWithBadRequest(): void
	{
		$this->seedIpMatcher(new FirewallIpMatcher());
		$request = Request::create('/api/items?'.str_repeat('a=b&', 40));

		[$event] = $this->runPipeline($request, $this->policy(['dos' => ['maxUriLength' => 20]]));

		self::assertSame(Response::HTTP_BAD_REQUEST, $event->getResponse()?->getStatusCode());
	}

	public function testPreAuthRateLimitReturnsTooManyRequestsWithHeaders(): void
	{
		$this->seedIpMatcher(new FirewallIpMatcher());
		$rule = $this->rateRule('core:test-pre', 'pre_auth-only', 1);
		$policy = $this->policy(['rateLimits' => [$rule]]);

		[$first] = $this->runPipeline($this->apiRequest(), $policy);
		self::assertNull($first->getResponse());

		[$second, $securityEvents] = $this->runPipeline($this->apiRequest(), $policy);
		$response = $second->getResponse();
		self::assertInstanceOf(Response::class, $response);
		self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
		self::assertSame('Too many requests.', json_decode((string) $response->getContent(), true)['error']);
		self::assertNotNull($response->headers->get('Retry-After'));
		self::assertSame('rate_limit', $securityEvents[0]->stageMetadata['denied_by']);
	}

	public function testPostAuthRateLimitReturnsTooManyRequestsForAuthenticatedUser(): void
	{
		$rule = $this->rateRule('core:test-post', 'post_auth-only', 1, 'user');
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn(new class implements \Symfony\Component\Security\Core\User\UserInterface {
			public function getRoles(): array { return ['ROLE_USER']; }
			public function eraseCredentials(): void {}
			public function getUserIdentifier(): string { return 'jim@example.test'; }
		});
		$manager = new FirewallRateLimiterManager($this->cache);
		$subscriber = new FirewallFeedbackSubscriber($security, $manager, new FirewallAnomalyDetector($this->cache, new NullLogger()), $this->autoBans(), new NullLogger());

		$request = Request::create('/api/items');
		$request->attributes->set(FirewallRequestSubscriber::CONTEXT_ATTRIBUTE, [
			'policy' => ['rateLimits' => [$rule], 'dos' => $this->dosDefaults(), 'bots' => []],
			'clientIp' => '192.0.2.10',
			'siteId' => null,
			'routeGroup' => 'api',
			'stage' => [],
		]);
		$kernel = $this->createStub(HttpKernelInterface::class);
		$first = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
		$subscriber->onPostAuthRequest($first);
		self::assertNull($first->getResponse());

		$second = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
		$subscriber->onPostAuthRequest($second);
		self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $second->getResponse()?->getStatusCode());
	}

	public function testResponseCarriesRateLimitHeadersFromStageOne(): void
	{
		$decision = new \iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimitDecision(true, 'core:r', 'R', 'report', 10, 7, new \DateTimeImmutable('+60 seconds'), 0);
		$request = Request::create('/api/items');
		$request->attributes->set(FirewallRequestSubscriber::RATE_LIMIT_ATTRIBUTE, $decision);
		$response = new Response('ok');
		$subscriber = $this->feedback();

		$subscriber->onKernelResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

		self::assertSame('10', $response->headers->get('X-RateLimit-Limit'));
		self::assertSame('7', $response->headers->get('X-RateLimit-Remaining'));
	}

	public function testFourXxStormEscalatesToAutoBanWhenEnforced(): void
	{
		$this->seedIpMatcher(new FirewallIpMatcher());
		$dos = $this->dosDefaults([
			'errorStormThreshold' => 1,
			'errorStormWindow' => 60,
			'errorStormAction' => 'enforce',
			'autoBanEnabled' => true,
			'autoBanAction' => 'enforce',
			'autoBanStrikeThreshold' => 1,
			'autoBanWindow' => 300,
			'autoBanDurations' => [300],
		]);
		$request = Request::create('/api/items');
		$request->attributes->set(FirewallRequestSubscriber::CONTEXT_ATTRIBUTE, [
			'policy' => ['rateLimits' => [], 'dos' => $dos, 'bots' => []],
			'clientIp' => '192.0.2.44',
			'siteId' => null,
			'routeGroup' => 'api',
			'stage' => [],
		]);
		$response = new Response('nope', Response::HTTP_NOT_FOUND);
		$feedback = $this->feedback();
		$feedback->onKernelResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
		$feedback->onKernelResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

		// The storm threshold is exceeded on the second 404; the strike then escalates through the ban manager.
		self::assertSame(2, $this->cacheCountFor('err', '192.0.2.44'));
		self::assertSame(1, $this->persistedBanCount, 'Enforced error storm must persist an auto-ban.');
	}

	public function testConcurrencySlotIsReleasedOnTerminate(): void
	{
		$anomalies = new FirewallAnomalyDetector($this->cache, new NullLogger());
		$config = $this->dosDefaults(['expensiveRouteConcurrencyPerIp' => 1, 'expensiveRouteConcurrencyPerSite' => 10]);
		$slot = $anomalies->acquireConcurrencySlot('192.0.2.77', 'search', $config, null);
		self::assertTrue($slot->acquired);
		self::assertFalse($anomalies->acquireConcurrencySlot('192.0.2.77', 'search', $config, null)->acquired);

		$request = Request::create('/api/search');
		$request->attributes->set(FirewallRequestSubscriber::CONCURRENCY_SLOT_ATTRIBUTE, $slot->slotKey);
		$this->feedback()->onKernelTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response()));

		self::assertTrue($anomalies->acquireConcurrencySlot('192.0.2.77', 'search', $config, null)->acquired);
	}

	public function testSiteLessNonApiRequestIsNotEvaluatedByStageOne(): void
	{
		$this->seedIpMatcher(new FirewallIpMatcher([$this->ipRule('block-all', '0.0.0.0/0', 'block')]));
		[$event, $securityEvents] = $this->runPipeline(Request::create('/login'));

		self::assertNull($event->getResponse());
		self::assertSame([], $securityEvents);
	}

	public function testSiteBoundRequestResolvesSitePolicyThroughResolver(): void
	{
		$this->seedIpMatcher(new FirewallIpMatcher());
		$site = $this->site(17);
		SiteRegistry::pushSite($site);
		$resolver = $this->createMock(FirewallProtectionPolicyResolverInterface::class);
		$resolver->expects(self::once())->method('resolveFor')->with(self::identicalTo($site), true)
			->willReturn($this->policy());

		$this->runPipeline($this->apiRequest(), null, $resolver, $site);
	}

	/**
	 * Builds the subscriber with real Stage-1 collaborators and the given policy, then dispatches the request.
	 *
	 * @return array{RequestEvent,list<FirewallSecurityEvent>}
	 */
	private function runPipeline(Request $request, ?FirewallResolvedPolicy $policy = null, ?FirewallProtectionPolicyResolverInterface $resolver = null, ?Site $site = null): array
	{
		$this->clearCurrentSite();
		if (null !== $site) {
			SiteRegistry::pushSite($site);
		}

		$conditions = new FirewallConditionRegistry([new RequestMatchCondition()]);
		$validator = new FirewallRuleValidator($conditions);
		$ruleCache = new FirewallRuleSetCache(new ArrayAdapter(), new NullLogger());
		$sitePolicies = new FirewallSitePolicyStore();
		$rules = new FirewallRuleSetResolver(
			new CoreFirewallRules(),
			new FirewallRulePackStore(sys_get_temp_dir().'/iikiti-pipeline-test', new FirewallRulePackSourceRegistry(), $validator, $ruleCache),
			$sitePolicies,
			$validator,
			$conditions,
			$ruleCache,
		);

		$dispatcher = new EventDispatcher();
		$securityEvents = [];
		$dispatcher->addListener(FirewallSecurityEvent::NAME, static function (FirewallSecurityEvent $event) use (&$securityEvents): void {
			$securityEvents[] = $event;
		});

		$policyResolver = $resolver ?? $this->fixedResolver($policy ?? $this->policy());
		$services = new FirewallStageOneServices(
			policyResolver: $policyResolver,
			ipRules: $this->ipRuleStore(),
			bots: $this->botDetector(),
			challenges: new FirewallChallengeManager([]),
			dosGuard: new FirewallDosGuard(),
			rateLimiter: new FirewallRateLimiterManager($this->cache),
			anomalies: new FirewallAnomalyDetector($this->cache, new NullLogger()),
			autoBans: $this->autoBans(),
			routes: new FirewallRouteClassifier(),
		);
		$subscriber = new FirewallRequestSubscriber(
			new FirewallRequestContextFactory(),
			$rules,
			new FirewallRuleEvaluator($validator, $conditions),
			$dispatcher,
			new NullLogger(),
			$services,
		);

		$kernel = $this->createStub(HttpKernelInterface::class);
		$event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
		$subscriber->onKernelRequest($event);

		return [$event, $securityEvents];
	}

	private function feedback(): FirewallFeedbackSubscriber
	{
		return new FirewallFeedbackSubscriber(
			$this->createStub(Security::class),
			new FirewallRateLimiterManager($this->cache),
			new FirewallAnomalyDetector($this->cache, new NullLogger()),
			$this->autoBans(),
			new NullLogger(),
		);
	}

	private function autoBans(): FirewallAutoBanManager
	{
		$bans = $this->createStub(FirewallIpBanRepository::class);
		$bans->method('findActiveOneByCidr')->willReturn(null);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('createQueryBuilder')->willReturnCallback(function () {
			$this->persistedBanCount = 0;

			return $this->createStub(\Doctrine\ORM\QueryBuilder::class);
		});
		$entityManager->method('persist')->willReturnCallback(function (): void {
			++$this->persistedBanCount;
		});

		return new FirewallAutoBanManager(
			$bans,
			$entityManager,
			$this->ipRuleStore(),
			$this->cache,
			new NullLogger(),
		);
	}

	private function botDetector(): FirewallBotDetector
	{
		$automations = $this->createStub(FirewallTrustedAutomationRepository::class);
		$automations->method('findForSiteAndGlobal')->willReturn([]);
		$verifier = (new \ReflectionClass(FirewallBotVerifier::class))->newInstanceWithoutConstructor();

		return new FirewallBotDetector(new FirewallBotCatalog(), $verifier, $automations);
	}

	private function ipRuleStore(): FirewallIpRuleStore
	{
		return new FirewallIpRuleStore($this->createStub(FirewallIpRuleRepository::class), $this->matcherCache, new NullLogger());
	}

	private function seedIpMatcher(FirewallIpMatcher $matcher): void
	{
		$item = $this->matcherCache->getItem('firewall_ip_matcher_v1_'.hash('sha256', '0'));
		$item->set($matcher);
		$this->matcherCache->save($item);
	}

	private function ipRule(string $id, string $cidr, string $action): FirewallIpRuleSpec
	{
		return new FirewallIpRuleSpec($id, $cidr, $action, 'required', 'global');
	}

	private function fixedResolver(FirewallResolvedPolicy $policy): FirewallProtectionPolicyResolverInterface
	{
		$resolver = $this->createStub(FirewallProtectionPolicyResolverInterface::class);
		$resolver->method('resolveFor')->willReturn($policy);

		return $resolver;
	}

	/** @param array<string,mixed> $overrides */
	private function policy(array $overrides = []): FirewallResolvedPolicy
	{
		return new FirewallResolvedPolicy(
			rateLimits: $overrides['rateLimits'] ?? [],
			dos: $this->dosDefaults($overrides['dos'] ?? []),
			bots: $overrides['bots'] ?? ['enabled' => false],
		);
	}

	/**
	 * @param array<string,mixed> $overrides
	 *
	 * @return array<string,mixed>
	 */
	private function dosDefaults(array $overrides = []): array
	{
		return array_replace(CoreProtectionPolicyDefaults::dos(), $overrides);
	}

	/** @return array<string,mixed> */
	private function rateRule(string $id, string $name, int $limit, string $keyBy = 'ip'): array
	{
		return [
			'id' => $id,
			'name' => $name,
			'enabled' => true,
			'priority' => 0,
			'inheritance' => 'required',
			'action' => 'throttle',
			'algorithm' => 'fixed_window',
			'limit' => $limit,
			'interval' => 60,
			'keyBy' => $keyBy,
			'match' => ['methods' => [], 'pathPrefix' => '', 'pathRegex' => null, 'routeGroup' => 'custom', 'authenticated' => 'any'],
		];
	}

	private function apiRequest(): Request
	{
		$request = Request::create('/api/items');
		$request->server->set('REMOTE_ADDR', '192.0.2.10');

		return $request;
	}

	private function site(int $id): Site
	{
		$site = new Site();
		(new \ReflectionProperty(\iikiti\CMS\Entity\DbObject::class, 'id'))->setValue($site, $id);
		$site->setProperties(new \iikiti\CMS\Doctrine\Collections\ArrayCollection());

		return $site;
	}

	private function cacheCountFor(string $scope, string $ip): int
	{
		$subject = \iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer::subnetKey($ip) ?? $ip;
		$key = 'fw_anomaly_v1_'.hash('sha256', implode("\0", [$scope, '0', $subject]));

		return (int) $this->cache->getItem($key)->get();
	}

	private function clearCurrentSite(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
	}
}
