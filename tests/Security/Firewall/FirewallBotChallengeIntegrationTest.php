<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\FirewallIpBanRepository;
use iikiti\CMS\Repository\Object\FirewallIpRuleRepository;
use iikiti\CMS\Repository\Object\FirewallTrustedAutomationRepository;
use iikiti\CMS\Security\Firewall\Bot\DnsLookupInterface;
use iikiti\CMS\Security\Firewall\Bot\FirewallAutomationSecretCipher;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotCatalog;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotCidrFeedStore;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotDetector;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotVerifier;
use iikiti\CMS\Security\Firewall\Bot\FirewallFcrdnsVerifier;
use iikiti\CMS\Security\Firewall\Challenge\CoreProofOfWorkChallengeProvider;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeCookie;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeManager;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengePageRenderer;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeToken;
use iikiti\CMS\Security\Firewall\CoreFirewallRules;
use iikiti\CMS\Security\Firewall\Dos\FirewallAnomalyDetector;
use iikiti\CMS\Security\Firewall\Dos\FirewallAutoBanManager;
use iikiti\CMS\Security\Firewall\Dos\FirewallDosGuard;
use iikiti\CMS\Security\Firewall\FirewallConditionRegistry;
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
use iikiti\CMS\Security\Firewall\Condition\RequestMatchCondition;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpMatcher;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use iikiti\CMS\Security\Firewall\Policy\CoreProtectionPolicyDefaults;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyResolverInterface;
use iikiti\CMS\Security\Firewall\Policy\FirewallResolvedPolicy;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRateLimiterManager;
use iikiti\CMS\Security\Firewall\RateLimit\FirewallRouteClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Exercises the real bot classification, CIDR verification, and proof-of-work challenge stack
 * through the Stage-1 pipeline. Only the Stage-1 DNS lookup and the ratelimit/matcher caches are
 * isolated; the feed is a real signed-on-disk CIDR list written to a temp directory.
 */
final class FirewallBotChallengeIntegrationTest extends TestCase
{
	private const SECRET = 'bot-challenge-integration-secret';
	private const GOOGLE_CIDR = '66.249.64.0/19';
	private const GOOGLE_IP = '66.249.66.1';
	private const IMPOSTOR_IP = '203.0.113.99';
	private const HUMAN_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

	private ArrayAdapter $cache;
	private ArrayAdapter $matcherCache;
	private string $packDirectory;

	protected function setUp(): void
	{
		parent::setUp();
		$this->cache = new ArrayAdapter();
		$this->matcherCache = new ArrayAdapter();
		$this->packDirectory = sys_get_temp_dir().'/iikiti-bot-it-'.bin2hex(random_bytes(6)).'/rule-packs';
		mkdir($this->packDirectory, 0o775, true);
		$this->clearCurrentSite();
		$this->seedMatcher(new FirewallIpMatcher());
	}

	protected function tearDown(): void
	{
		$this->clearCurrentSite();
		$this->removeDirectory(dirname($this->packDirectory));
		parent::tearDown();
	}

	public function testGptBotIsBlockedByDefaultAiTrainingPosture(): void
	{
		$this->seedGoogleFeed();
		$request = $this->botRequest('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', '203.0.113.50');

		[$event, $securityEvents] = $this->runStageOne($request, $this->botPolicy());

		$response = $event->getResponse();
		self::assertInstanceOf(Response::class, $response);
		self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
		self::assertSame('bot_block', $securityEvents[0]->stageMetadata['denied_by']);
		self::assertSame('ai_training', $securityEvents[0]->stageMetadata['bot']['category']);
	}

	public function testGooglebotImpersonatorIsChallengedWhenCidrFeedLoaded(): void
	{
		$this->seedGoogleFeed();
		$request = $this->botRequest('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', self::IMPOSTOR_IP, 'text/html,application/xhtml+xml');

		[$event, $securityEvents] = $this->runStageOne($request, $this->botPolicy(['impersonationAction' => 'challenge', 'blockImpersonators' => true]));

		$response = $event->getResponse();
		self::assertInstanceOf(Response::class, $response);
		self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
		// The interstitial is the core proof-of-work page; it must carry the challenge payload.
		self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
		self::assertNotSame('', (string) $response->getContent());
		self::assertContains('bot:impersonation', array_map(static fn ($f) => $f->ruleId, $securityEvents[0]->findings));
	}

	public function testGooglebotImpersonatorIsBlockedWhenPolicyBlocks(): void
	{
		$this->seedGoogleFeed();
		$request = $this->botRequest('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', self::IMPOSTOR_IP);

		[$event, $securityEvents] = $this->runStageOne($request, $this->botPolicy(['impersonationAction' => 'block', 'blockImpersonators' => true]));

		self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
		self::assertSame('bot_block', $securityEvents[0]->stageMetadata['denied_by']);
		self::assertTrue($securityEvents[0]->stageMetadata['bot']['impersonated']);
	}

	public function testVerifiedGooglebotFromCidrRangeIsAllowed(): void
	{
		$this->seedGoogleFeed();
		$request = $this->botRequest('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', self::GOOGLE_IP);

		[$event, $securityEvents] = $this->runStageOne($request, $this->botPolicy(['impersonationAction' => 'block', 'blockImpersonators' => true]));

		self::assertNull($event->getResponse());
		self::assertSame([], $securityEvents);
		self::assertFalse($request->attributes->get(FirewallRequestSubscriber::CONTEXT_ATTRIBUTE)['stage']['bot']['impersonated']);
	}

	public function testValidClearanceCookieAllowsChallengedClientThrough(): void
	{
		$this->seedGoogleFeed();
		$ip = self::IMPOSTOR_IP;
		$ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$policy = $this->botPolicy(['impersonationAction' => 'challenge', 'blockImpersonators' => true]);

		$clearance = $this->clearanceCookieFor($ip, $ua);
		$request = $this->botRequest($ua, $ip, 'text/html,application/xhtml+xml');
		$request->cookies->set(FirewallChallengeCookie::NAME, $clearance);

		[$event] = $this->runStageOne($request, $policy);

		self::assertNull($event->getResponse(), 'A valid iikiti_fw_clearance cookie must bypass the bot challenge.');
	}

	public function testClearanceCookieIsRejectedForDifferentClientIp(): void
	{
		$this->seedGoogleFeed();
		$ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$policy = $this->botPolicy(['impersonationAction' => 'challenge', 'blockImpersonators' => true]);

		$request = $this->botRequest($ua, self::IMPOSTOR_IP, 'text/html,application/xhtml+xml');
		$request->cookies->set(FirewallChallengeCookie::NAME, $this->clearanceCookieFor('198.51.100.200', $ua));

		[$event] = $this->runStageOne($request, $policy);

		self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()?->getStatusCode());
	}

	public function testHumanBrowserIsUnaffectedByBotPipeline(): void
	{
		$this->seedGoogleFeed();
		$request = $this->botRequest(self::HUMAN_UA, '198.51.100.20', 'text/html');

		[$event, $securityEvents] = $this->runStageOne($request, $this->botPolicy());

		self::assertNull($event->getResponse());
		self::assertSame([], $securityEvents);
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function botPolicy(array $overrides = []): FirewallResolvedPolicy
	{
		$bots = array_replace(CoreProtectionPolicyDefaults::bots(), ['enabled' => true], $overrides);
		$dos = CoreProtectionPolicyDefaults::dos();
		$dos['enabled'] = false;

		return new FirewallResolvedPolicy([], $dos, $bots);
	}

	private function botRequest(string $userAgent, string $ip, string $accept = 'text/html'): Request
	{
		$request = Request::create('https://iikiti.example.test/landing', 'GET', [], [], [], [
			'HTTP_USER_AGENT' => $userAgent,
			'HTTP_ACCEPT' => $accept,
			'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
			'REMOTE_ADDR' => $ip,
		]);

		return $request;
	}

	private function clearanceCookieFor(string $ip, string $userAgent): string
	{
		$request = $this->botRequest($userAgent, $ip);
		$cookie = $this->cookieService()->issue($request);

		return (string) $cookie->getValue();
	}

	private function cookieService(): FirewallChallengeCookie
	{
		return new FirewallChallengeCookie($this->tokens());
	}

	private function tokens(): FirewallChallengeToken
	{
		return new FirewallChallengeToken(self::SECRET);
	}

	/** Writes a real CIDR feed for Google's crawler source and loads it through the store. */
	private function seedGoogleFeed(): void
	{
		$this->feedStore()->saveFeed('bot-google', [self::GOOGLE_CIDR]);
	}

	private function feedStore(): FirewallBotCidrFeedStore
	{
		return new FirewallBotCidrFeedStore($this->packDirectory.'/pack.json', $this->cache, new NullLogger(), []);
	}

	/**
	 * @return array{0: RequestEvent, 1: list<FirewallSecurityEvent>}
	 */
	private function runStageOne(Request $request, FirewallResolvedPolicy $policy, string $applicationRole = 'consumer'): array
	{
		$conditions = new FirewallConditionRegistry([new RequestMatchCondition()]);
		$validator = new FirewallRuleValidator($conditions);
		$ruleCache = new FirewallRuleSetCache(new ArrayAdapter(), new NullLogger());
		$rules = new FirewallRuleSetResolver(
			new CoreFirewallRules(),
			new FirewallRulePackStore($this->packDirectory, new FirewallRulePackSourceRegistry(), $validator, $ruleCache),
			new FirewallSitePolicyStore(),
			$validator,
			$conditions,
			$ruleCache,
		);

		$dispatcher = new EventDispatcher();
		$securityEvents = [];
		$dispatcher->addListener(FirewallSecurityEvent::NAME, static function (FirewallSecurityEvent $event) use (&$securityEvents): void {
			$securityEvents[] = $event;
		});

		$services = new FirewallStageOneServices(
			policyResolver: $this->fixedResolver($policy),
			ipRules: $this->ipRuleStore(),
			bots: $this->detector(),
			challenges: $this->challenges(),
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
			$applicationRole,
		);

		$event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
		$subscriber->onKernelRequest($event);

		return [$event, $securityEvents];
	}

	private function detector(): FirewallBotDetector
	{
		$automations = $this->createStub(FirewallTrustedAutomationRepository::class);
		$automations->method('findForSiteAndGlobal')->willReturn([]);

		$dns = $this->createStub(DnsLookupInterface::class);
		$dns->method('reverseLookup')->willReturn(null);
		$fcrdns = new FirewallFcrdnsVerifier($dns, $this->cache);
		$verifier = new FirewallBotVerifier(
			$this->feedStore(),
			$fcrdns,
			new FirewallAutomationSecretCipher(self::SECRET),
		);

		return new FirewallBotDetector(new FirewallBotCatalog(), $verifier, $automations);
	}

	private function challenges(): FirewallChallengeManager
	{
		$provider = new CoreProofOfWorkChallengeProvider(
			$this->tokens(),
			new FirewallChallengeCookie($this->tokens()),
			new FirewallChallengePageRenderer(),
		);

		return new FirewallChallengeManager([$provider]);
	}

	private function fixedResolver(FirewallResolvedPolicy $policy): FirewallProtectionPolicyResolverInterface
	{
		return new class($policy) implements FirewallProtectionPolicyResolverInterface {
			public function __construct(private readonly FirewallResolvedPolicy $policy)
			{
			}

			public function resolveFor(?\iikiti\CMS\Entity\Object\Site $site, bool $includeSiteRules, ?string $host = null, ?string $clientIp = null): FirewallResolvedPolicy
			{
				return $this->policy;
			}
		};
	}

	private function ipRuleStore(): FirewallIpRuleStore
	{
		return new FirewallIpRuleStore($this->createStub(FirewallIpRuleRepository::class), $this->matcherCache, new NullLogger());
	}

	private function seedMatcher(FirewallIpMatcher $matcher): void
	{
		$item = $this->matcherCache->getItem('firewall_ip_matcher_v1_'.hash('sha256', '0'));
		$item->set($matcher);
		$this->matcherCache->save($item);
	}

	private function autoBans(): FirewallAutoBanManager
	{
		$bans = $this->createStub(FirewallIpBanRepository::class);
		$bans->method('findActiveOneByCidr')->willReturn(null);
		$entityManager = $this->createStub(EntityManagerInterface::class);

		return new FirewallAutoBanManager($bans, $entityManager, $this->ipRuleStore(), $this->cache, new NullLogger());
	}

	private function clearCurrentSite(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
	}

	private function removeDirectory(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}
		foreach (scandir($path) ?: [] as $entry) {
			if ('.' === $entry || '..' === $entry) {
				continue;
			}
			$full = $path.'/'.$entry;
			is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
		}
		@rmdir($path);
	}
}
