<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Entity\Object\FirewallTrustedAutomation;
use iikiti\CMS\Repository\Object\FirewallTrustedAutomationRepository;
use iikiti\CMS\Security\Firewall\Bot\DnsLookupInterface;
use iikiti\CMS\Security\Firewall\Bot\FirewallAutomationSecretCipher;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotCatalog;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotCidrFeedStore;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotDetector;
use iikiti\CMS\Security\Firewall\Bot\FirewallBotVerifier;
use iikiti\CMS\Security\Firewall\Bot\FirewallFcrdnsVerifier;
use iikiti\CMS\Security\Firewall\Policy\CoreProtectionPolicyDefaults;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

#[AllowMockObjectsWithoutExpectations]
final class FirewallBotDetectionTest extends TestCase
{
	private const GOOGLE_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
	private const BROWSER_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
	private const GOOGLE_RANGE = '66.249.64.0/19';
	private const GOOD_IP = '66.249.66.1';
	private const SECRET = 'correct-horse-battery-staple';

	private string $tempDirectory;
	private ArrayAdapter $cache;
	private FirewallBotCatalog $catalog;

	protected function setUp(): void
	{
		$this->tempDirectory = sys_get_temp_dir().'/iikiti-bot-'.bin2hex(random_bytes(6));
		$this->cache = new ArrayAdapter();
		$this->catalog = new FirewallBotCatalog();
	}

	protected function tearDown(): void
	{
		$this->removeDirectory($this->tempDirectory);
	}

	public function testCatalogMatchesKnownAgentsAndNeverFlagsCurlAsScanner(): void
	{
		self::assertSame('googlebot', $this->catalog->matchUserAgent(self::GOOGLE_UA)?->id);
		self::assertSame('gptbot', $this->catalog->matchUserAgent('Mozilla/5.0; GPTBot/1.2')?->id);
		self::assertSame('sqlmap', $this->catalog->matchUserAgent('sqlmap/1.7')?->id);
		self::assertNull($this->catalog->matchUserAgent('curl/8.5.0'));
		self::assertNull($this->catalog->matchUserAgent('python-requests/2.31'));
		self::assertNull($this->catalog->get('unknown-bot'));
	}

	public function testVerifiedBotPassesSignedCidrFeedAndSpoofedBotIsImpersonating(): void
	{
		$detector = $this->detector();
		$this->feeds()->saveFeed('bot-google', [self::GOOGLE_RANGE]);

		$verified = $detector->evaluate($this->request(self::GOOGLE_UA), self::GOOD_IP, 'public', $this->botsConfig(), null);
		$spoofed = $detector->evaluate($this->request(self::GOOGLE_UA), '203.0.113.9', 'public', $this->botsConfig(), null);

		self::assertTrue($verified->verified);
		self::assertFalse($verified->impersonated);
		self::assertSame('allow', $verified->action);
		self::assertTrue($spoofed->impersonated);
		self::assertSame('block', $spoofed->action);
		self::assertSame('bot:impersonation', $spoofed->findings[0]->ruleId);
	}

	public function testMissingFeedDoesNotFlagImpersonation(): void
	{
		$result = $this->detector()->evaluate($this->request(self::GOOGLE_UA), '203.0.113.9', 'public', $this->botsConfig(), null);

		self::assertFalse($result->impersonated);
		self::assertFalse($result->verified);
		self::assertSame([], $result->findings);
		self::assertSame('allow', $result->action);
	}

	public function testFcrdnsIsOptInAndUsesBoundedAllowlistedSuffix(): void
	{
		$dns = $this->createMock(DnsLookupInterface::class);
		$dns->expects(self::once())->method('reverseLookup')->with('203.0.113.9')->willReturn('crawl-203-0-113-9.googlebot.com');
		$dns->expects(self::once())->method('forwardLookup')->with('crawl-203-0-113-9.googlebot.com')->willReturn(['203.0.113.9']);
		$detector = $this->detector($dns);

		$config = $this->botsConfig(['fcrdnsEnabled' => true]);
		$result = $detector->evaluate($this->request(self::GOOGLE_UA), '203.0.113.9', 'public', $config, null);

		self::assertTrue($result->verified);
		self::assertFalse($result->impersonated);
	}

	public function testFcrdnsDisabledByDefaultNeverPerformsLookups(): void
	{
		$dns = $this->createMock(DnsLookupInterface::class);
		$dns->expects(self::never())->method('reverseLookup');
		$result = $this->detector($dns)->evaluate($this->request(self::GOOGLE_UA), '203.0.113.9', 'public', $this->botsConfig(), null);

		self::assertFalse($result->impersonated);
	}

	public function testFcrdnsRejectsSuffixLookalikes(): void
	{
		$dns = $this->createMock(DnsLookupInterface::class);
		$dns->method('reverseLookup')->willReturn('evilgooglebot.com');
		$dns->expects(self::never())->method('forwardLookup');
		$result = $this->detector($dns)->evaluate($this->request(self::GOOGLE_UA), '203.0.113.9', 'public', $this->botsConfig(['fcrdnsEnabled' => true]), null);

		self::assertTrue($result->impersonated);
	}

	public function testHeaderSecretAutomationBypassesWafOnlyWithMatchingSecret(): void
	{
		$automation = $this->automation('header_secret', ['header_name' => 'X-Automation-Key']);
		$automation->setSecretMaterial($this->cipher()->hashHeaderSecret(self::SECRET));
		$automation->setBypassWaf(true);
		$detector = $this->detector(automations: [$automation]);

		$request = $this->request(self::BROWSER_UA, headers: ['X-Automation-Key' => self::SECRET]);
		$allowed = $detector->evaluate($request, '198.51.100.7', 'api', $this->botsConfig(), null);
		$denied = $detector->evaluate($this->request(self::BROWSER_UA, headers: ['X-Automation-Key' => 'wrong']), '198.51.100.7', 'api', $this->botsConfig(), null);

		self::assertSame('allow', $allowed->action);
		self::assertTrue($allowed->bypassWaf);
		self::assertSame('ci', $allowed->trustedAutomationId);
		self::assertNull($denied->trustedAutomationId);
	}

	public function testCidrAutomationMatchesOnlyInsideRange(): void
	{
		$automation = $this->automation('cidr', []);
		$automation->setCidrs(['198.51.100.0/24']);
		$detector = $this->detector(automations: [$automation]);

		$inside = $detector->evaluate($this->request(self::BROWSER_UA), '198.51.100.44', 'api', $this->botsConfig(), null);
		$outside = $detector->evaluate($this->request(self::BROWSER_UA), '203.0.113.44', 'api', $this->botsConfig(), null);

		self::assertSame('ci', $inside->trustedAutomationId);
		self::assertNull($outside->trustedAutomationId);
	}

	public function testHmacAutomationVerifiesSignatureAndRejectsStaleTimestamp(): void
	{
		$automation = $this->automation('hmac', ['header_name' => 'X-Hub-Signature-256']);
		$automation->setSecretMaterial($this->cipher()->encryptHmacSecret(self::SECRET));
		$detector = $this->detector(automations: [$automation]);

		$timestamp = (string) time();
		$signature = 'sha256='.hash_hmac('sha256', "POST\n/api/hooks/deploy\n".$timestamp, self::SECRET);
		$valid = $this->request(self::BROWSER_UA, 'POST', '/api/hooks/deploy', ['X-Hub-Signature-256' => $signature, 'X-Timestamp' => $timestamp]);
		$stale = (string) (time() - 3600);
		$staleSignature = 'sha256='.hash_hmac('sha256', "POST\n/api/hooks/deploy\n".$stale, self::SECRET);
		$expired = $this->request(self::BROWSER_UA, 'POST', '/api/hooks/deploy', ['X-Hub-Signature-256' => $staleSignature, 'X-Timestamp' => $stale]);

		self::assertSame('ci', $detector->evaluate($valid, '198.51.100.7', 'api', $this->botsConfig(), null)->trustedAutomationId);
		self::assertNull($detector->evaluate($expired, '198.51.100.7', 'api', $this->botsConfig(), null)->trustedAutomationId);
	}

	public function testHttpSignatureAutomationVerifiesEd25519Signature(): void
	{
		$keyPair = sodium_crypto_sign_keypair();
		$publicKey = sodium_crypto_sign_publickey($keyPair);
		$secretKey = sodium_crypto_sign_secretkey($keyPair);
		$automation = $this->automation('http_signature', []);
		$automation->setPublicKey(base64_encode($publicKey));
		$detector = $this->detector(automations: [$automation]);

		$timestamp = (string) time();
		$signature = base64_encode(sodium_crypto_sign_detached("GET\n/api/mcp\n".$timestamp, $secretKey));
		$signed = $this->request(self::BROWSER_UA, 'GET', '/api/mcp', ['X-Bot-Signature' => $signature, 'X-Timestamp' => $timestamp]);
		$unsigned = $this->request(self::BROWSER_UA, 'GET', '/api/mcp', ['X-Timestamp' => $timestamp]);

		self::assertSame('ci', $detector->evaluate($signed, '198.51.100.7', 'api', $this->botsConfig(), null)->trustedAutomationId);
		self::assertNull($detector->evaluate($unsigned, '198.51.100.7', 'api', $this->botsConfig(), null)->trustedAutomationId);
	}

	public function testAutomationPathPrefixesRestrictMatches(): void
	{
		$automation = $this->automation('cidr', []);
		$automation->setCidrs(['198.51.100.0/24']);
		$automation->setAllowedPaths(['/api/hooks']);
		$detector = $this->detector(automations: [$automation]);

		$allowedPath = $detector->evaluate($this->request(self::BROWSER_UA, 'GET', '/api/hooks/x'), '198.51.100.5', 'api', $this->botsConfig(), null);
		$otherPath = $detector->evaluate($this->request(self::BROWSER_UA, 'GET', '/api/content'), '198.51.100.5', 'api', $this->botsConfig(), null);

		self::assertSame('ci', $allowedPath->trustedAutomationId);
		self::assertNull($otherPath->trustedAutomationId);
	}

	public function testHoneypotPathTriggersConfiguredAction(): void
	{
		$config = $this->botsConfig(['honeypotEnabled' => true, 'honeypotPaths' => ['/trap'], 'honeypotAction' => 'report']);
		$result = $this->detector()->evaluate($this->request(self::BROWSER_UA, 'GET', '/trap'), '203.0.113.9', 'public', $config, null);

		self::assertSame('report', $result->action);
		self::assertSame('bot:honeypot', $result->findings[0]->ruleId);
	}

	public function testHoneypotDisabledIgnoresTrapPath(): void
	{
		$config = $this->botsConfig(['honeypotEnabled' => false, 'honeypotPaths' => ['/trap']]);
		$browserHeaders = ['Accept' => 'text/html', 'Accept-Language' => 'en'];
		$result = $this->detector()->evaluate($this->request(self::BROWSER_UA, 'GET', '/trap', $browserHeaders), '203.0.113.9', 'public', $config, null);

		self::assertSame('none', $result->action);
		self::assertSame([], $result->findings);
	}

	public function testCategoryPostureAppliesAndPerBotOverrideWins(): void
	{
		$detector = $this->detector();
		$gpt = $this->request('Mozilla/5.0; GPTBot/1.2');
		$defaultTraining = $detector->evaluate($gpt, '203.0.113.9', 'public', $this->botsConfig(), null);
		$overridden = $detector->evaluate($gpt, '203.0.113.9', 'public', $this->botsConfig(['botOverrides' => ['gptbot' => 'report']]), null);

		self::assertSame('block', $defaultTraining->action);
		self::assertSame('ai_training', $defaultTraining->category);
		self::assertSame('report', $overridden->action);
	}

	public function testImpersonatorActionFollowsBlockImpersonatorsSetting(): void
	{
		$this->feeds()->saveFeed('bot-google', [self::GOOGLE_RANGE]);
		$detector = $this->detector();

		$reported = $detector->evaluate($this->request(self::GOOGLE_UA), '203.0.113.9', 'public', $this->botsConfig(['impersonationAction' => 'report']), null);
		$unblocked = $detector->evaluate($this->request(self::GOOGLE_UA), '203.0.113.9', 'public', $this->botsConfig(['blockImpersonators' => false]), null);

		self::assertTrue($reported->impersonated);
		self::assertSame('report', $reported->action);
		self::assertTrue($unblocked->impersonated);
		self::assertSame('search_engine', $unblocked->category);
		self::assertSame('allow', $unblocked->action);
	}

	public function testMissingBrowserHeadersOnPublicRouteAreReportedOnly(): void
	{
		$result = $this->detector()->evaluate($this->request(self::BROWSER_UA, headers: ['Accept' => '*/*']), '203.0.113.9', 'public', $this->botsConfig(), null);

		self::assertSame('report', $result->action);
		self::assertSame('bot:header-inconsistency', $result->findings[0]->ruleId);
	}

	public function testBrowserHeaderHeuristicIsSkippedOnApiRoutes(): void
	{
		$result = $this->detector()->evaluate($this->request(self::BROWSER_UA), '203.0.113.9', 'api', $this->botsConfig(), null);

		self::assertSame('none', $result->action);
	}

	public function testChallengeDowngradedToBlockOnApiPaths(): void
	{
		$config = $this->botsConfig(['categoryPostures' => ['ai_training' => 'challenge']]);
		$detector = $this->detector();

		$api = $detector->evaluate($this->request('Mozilla/5.0; GPTBot/1.2', 'GET', '/api/content'), '203.0.113.9', 'api', $config, null);
		$page = $detector->evaluate($this->request('Mozilla/5.0; GPTBot/1.2', 'GET', '/news'), '203.0.113.9', 'public', $config, null);

		self::assertSame('block', $api->action);
		self::assertSame('challenge', $page->action);
	}

	public function testDisabledBotPolicyReturnsNone(): void
	{
		$result = $this->detector()->evaluate($this->request('sqlmap/1.7'), '203.0.113.9', 'public', $this->botsConfig(['enabled' => false]), null);

		self::assertSame('none', $result->action);
	}

	/**
	 * @param array<string,mixed> $overrides
	 *
	 * @return array<string,mixed>
	 */
	private function botsConfig(array $overrides = []): array
	{
		return array_replace(CoreProtectionPolicyDefaults::bots(), $overrides);
	}

	/**
	 * @param list<FirewallTrustedAutomation> $automations
	 */
	private function detector(?DnsLookupInterface $dns = null, array $automations = []): FirewallBotDetector
	{
		$repository = $this->createStub(FirewallTrustedAutomationRepository::class);
		$repository->method('findForSiteAndGlobal')->willReturn($automations);
		$fcrdns = new FirewallFcrdnsVerifier($dns ?? $this->createStub(DnsLookupInterface::class), $this->cache);
		$verifier = new FirewallBotVerifier($this->feeds(), $fcrdns, $this->cipher());

		return new FirewallBotDetector($this->catalog, $verifier, $repository);
	}

	private function feeds(): FirewallBotCidrFeedStore
	{
		return new FirewallBotCidrFeedStore($this->tempDirectory.'/rule-packs', $this->cache, new NullLogger());
	}

	private function cipher(): FirewallAutomationSecretCipher
	{
		return new FirewallAutomationSecretCipher('test-application-secret');
	}

	/** @param array<string,mixed> $attributes */
	private function automation(string $authMode, array $attributes): FirewallTrustedAutomation
	{
		$automation = new FirewallTrustedAutomation();
		$automation->setName('CI');
		$automation->setHandle('ci');
		$automation->setAuthMode($authMode);
		if (isset($attributes['header_name'])) {
			$automation->setHeaderName($attributes['header_name']);
		}

		return $automation;
	}

	/**
	 * Builds a request with an explicit header bag. Request::create() injects default
	 * Accept/Accept-Language headers, which would hide the missing-header heuristic.
	 *
	 * @param array<string,string> $headers
	 */
	private function request(string $userAgent, string $method = 'GET', string $path = '/', array $headers = []): Request
	{
		$request = Request::create($path, $method, server: ['HTTP_USER_AGENT' => $userAgent]);
		$request->headers->remove('Accept');
		$request->headers->remove('Accept-Language');
		foreach ($headers as $name => $value) {
			$request->headers->set($name, $value);
		}

		return $request;
	}

	private function removeDirectory(string $directory): void
	{
		if (!is_dir($directory)) {
			return;
		}
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($directory);
	}
}
