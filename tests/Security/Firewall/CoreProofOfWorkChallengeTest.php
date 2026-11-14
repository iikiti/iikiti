<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Controller\FirewallChallengeController;
use iikiti\CMS\Security\Firewall\Challenge\CoreProofOfWorkChallengeProvider;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeCookie;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeManager;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengePageRenderer;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeToken;
use iikiti\CMS\Security\Firewall\Challenge\FirewallChallengeVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CoreProofOfWorkChallengeTest extends TestCase
{
	private const SECRET = 'test-application-secret';
	private const UA = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0 Safari/537.36';
	private const IP = '198.51.100.20';
	private const DIFFICULTY = 8;

	private FirewallChallengeToken $tokens;
	private FirewallChallengeCookie $cookies;
	private CoreProofOfWorkChallengeProvider $provider;
	private FirewallChallengeController $controller;

	protected function setUp(): void
	{
		$this->tokens = new FirewallChallengeToken(self::SECRET);
		$this->cookies = new FirewallChallengeCookie($this->tokens);
		$this->provider = new CoreProofOfWorkChallengeProvider($this->tokens, $this->cookies, new FirewallChallengePageRenderer());
		$this->controller = new FirewallChallengeController(new FirewallChallengeVerifier($this->tokens), $this->cookies);
	}

	public function testSupportsOnlyHtmlPageGetRequestsOutsideApi(): void
	{
		self::assertTrue($this->provider->supports($this->htmlRequest('/news')));
		self::assertFalse($this->provider->supports($this->htmlRequest('/api/content')));
		self::assertFalse($this->provider->supports($this->htmlRequest('/api')));
		self::assertFalse($this->provider->supports($this->htmlRequest('/news', 'POST')));
		self::assertFalse($this->provider->supports(Request::create('/news', 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => self::IP])));
	}

	public function testChallengeResponseIsNoStoreInterstitialWithSignedChallenge(): void
	{
		$response = $this->provider->createChallengeResponse($this->htmlRequest('/news'), ['challengeDifficulty' => 16], 'Automated traffic');

		self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
		self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
		self::assertStringContainsString('/_firewall/challenge/verify', $response->getContent() ?: '');
		self::assertStringContainsString('crypto.subtle.digest', $response->getContent() ?: '');
		self::assertStringContainsString('Automated traffic', $response->getContent() ?: '');
	}

	public function testChallengeDifficultyIsClampedToSupportedRange(): void
	{
		self::assertSame(8, CoreProofOfWorkChallengeProvider::clampDifficulty(1));
		self::assertSame(22, CoreProofOfWorkChallengeProvider::clampDifficulty(99));
		self::assertSame(16, CoreProofOfWorkChallengeProvider::clampDifficulty('bogus'));
	}

	public function testValidSolutionIssuesClearanceCookieAndRedirectsToReturnPath(): void
	{
		$challenge = $this->challengeFor('/news?page=2');
		$solution = $this->solve($challenge, self::DIFFICULTY);

		$response = $this->controller->__invoke($this->verifyRequest($challenge, $solution));

		self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
		self::assertSame('/news?page=2', $response->headers->get('Location'));
		$cookie = $response->headers->getCookies()[0] ?? null;
		self::assertNotNull($cookie);
		self::assertSame(FirewallChallengeCookie::NAME, $cookie->getName());
		self::assertTrue($cookie->isHttpOnly());
		self::assertSame('lax', $cookie->getSameSite());
		self::assertFalse($cookie->isSecure());
	}

	public function testIssuedClearanceCookieValidatesForSameClientOnly(): void
	{
		$challenge = $this->challengeFor('/');
		$response = $this->controller->__invoke($this->verifyRequest($challenge, $this->solve($challenge, self::DIFFICULTY)));
		$cookieValue = $response->headers->getCookies()[0]->getValue();

		self::assertTrue($this->cookies->isValid($this->htmlRequest('/news', cookie: $cookieValue)));
		self::assertFalse($this->cookies->isValid($this->htmlRequest('/news', ip: '203.0.113.99', cookie: $cookieValue)));
		self::assertFalse($this->cookies->isValid($this->htmlRequest('/news', ua: 'other-agent', cookie: $cookieValue)));
		self::assertFalse($this->cookies->isValid($this->htmlRequest('/news', cookie: $cookieValue.'x')));
	}

	public function testWrongSolutionIsRejected(): void
	{
		$challenge = $this->challengeFor('/');
		$response = $this->controller->__invoke($this->verifyRequest($challenge, '0'));

		self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
		self::assertFalse($response->headers->has('Set-Cookie'));
	}

	public function testMalformedSolutionIsRejected(): void
	{
		$challenge = $this->challengeFor('/');

		self::assertSame(403, $this->controller->__invoke($this->verifyRequest($challenge, 'abc'))->getStatusCode());
		self::assertSame(403, $this->controller->__invoke($this->verifyRequest($challenge, str_repeat('9', 21)))->getStatusCode());
	}

	public function testTamperedChallengeIsRejected(): void
	{
		$challenge = $this->challengeFor('/');
		$solution = $this->solve($challenge, self::DIFFICULTY);
		$tampered = substr($challenge, 0, -1).('a' === substr($challenge, -1) ? 'b' : 'a');

		self::assertSame(403, $this->controller->__invoke($this->verifyRequest($tampered, $solution))->getStatusCode());
	}

	public function testExpiredChallengeIsRejected(): void
	{
		$payload = $this->payload('/', self::IP, ['exp' => time() - 1]);
		$challenge = $this->tokens->sign($payload, CoreProofOfWorkChallengeProvider::CHALLENGE_PURPOSE);

		self::assertSame(403, $this->controller->__invoke($this->verifyRequest($challenge, $this->solve($challenge, self::DIFFICULTY)))->getStatusCode());
	}

	public function testChallengeIssuedBeyondTtlIsRejected(): void
	{
		$payload = $this->payload('/', self::IP, ['exp' => time() + 10 * CoreProofOfWorkChallengeProvider::CHALLENGE_TTL]);
		$challenge = $this->tokens->sign($payload, CoreProofOfWorkChallengeProvider::CHALLENGE_PURPOSE);

		self::assertSame(403, $this->controller->__invoke($this->verifyRequest($challenge, $this->solve($challenge, self::DIFFICULTY)))->getStatusCode());
	}

	public function testChallengeFromAnotherIpIsRejected(): void
	{
		$challenge = $this->challengeFor('/');
		$solution = $this->solve($challenge, self::DIFFICULTY);

		self::assertSame(403, $this->controller->__invoke($this->verifyRequest($challenge, $solution, ip: '203.0.113.50'))->getStatusCode());
	}

	public function testChallengeFromAnotherUserAgentIsRejected(): void
	{
		$challenge = $this->challengeFor('/');
		$solution = $this->solve($challenge, self::DIFFICULTY);

		self::assertSame(403, $this->controller->__invoke($this->verifyRequest($challenge, $solution, ua: 'other-agent'))->getStatusCode());
	}

	public function testChallengeTokenCannotBeReplayedAsClearanceCookie(): void
	{
		$challenge = $this->challengeFor('/');

		self::assertFalse($this->cookies->isValid($this->htmlRequest('/', cookie: $challenge)));
	}

	public function testOpenRedirectReturnPathsFallBackToRoot(): void
	{
		foreach (['//evil.example/phish', '/\\evil.example', 'https://evil.example/'] as $hostile) {
			$payload = $this->payload($hostile, self::IP);
			$challenge = $this->tokens->sign($payload, CoreProofOfWorkChallengeProvider::CHALLENGE_PURPOSE);
			$response = $this->controller->__invoke($this->verifyRequest($challenge, $this->solve($challenge, self::DIFFICULTY)));

			self::assertSame('/', $response->headers->get('Location'), 'Rejected return path: '.$hostile);
		}
	}

	public function testManagerReturnsCoreChallengeAndHonoursClearance(): void
	{
		$manager = new FirewallChallengeManager([$this->provider]);
		$request = $this->htmlRequest('/news');

		self::assertSame(429, $manager->challenge($request, ['challengeDifficulty' => 8], 'Bot')?->getStatusCode());
		self::assertFalse($manager->hasValidClearance($request, []));
		self::assertNull($manager->challenge($this->htmlRequest('/api/content'), [], 'Bot'));
	}

	private function challengeFor(string $returnTo): string
	{
		return $this->tokens->sign($this->payload($returnTo, self::IP), CoreProofOfWorkChallengeProvider::CHALLENGE_PURPOSE);
	}

	/**
	 * @param array<string,mixed> $overrides
	 *
	 * @return array<string,mixed>
	 */
	private function payload(string $returnTo, string $ip, array $overrides = []): array
	{
		return array_replace([
			'nonce' => bin2hex(random_bytes(16)),
			'ip' => $ip,
			'uaHash' => hash('sha256', self::UA),
			'exp' => time() + 120,
			'difficulty' => self::DIFFICULTY,
			'returnTo' => $returnTo,
		], $overrides);
	}

	/** Brute-forces the smallest counter satisfying the proof-of-work, mirroring the browser solver. */
	private function solve(string $challenge, int $difficulty): string
	{
		for ($counter = 0; $counter < 10_000_000; $counter++) {
			$digest = hash('sha256', $challenge.':'.$counter, true);
			if ($this->leadingZeroBits($digest) >= $difficulty) {
				return (string) $counter;
			}
		}

		self::fail('Unable to solve proof-of-work within bound.');
	}

	private function leadingZeroBits(string $bytes): int
	{
		$bits = 0;
		foreach (str_split($bytes) as $char) {
			$byte = ord($char);
			if (0 === $byte) {
				$bits += 8;
				continue;
			}
			for ($mask = 0x80; 0 !== $mask && 0 === ($byte & $mask); $mask >>= 1) {
				$bits++;
			}

			return $bits;
		}

		return $bits;
	}

	private function verifyRequest(string $challenge, string $solution, string $ip = self::IP, string $ua = self::UA): Request
	{
		return Request::create('/_firewall/challenge/verify', 'POST', [
			'challenge' => $challenge,
			'solution' => $solution,
		], server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]);
	}

	private function htmlRequest(string $path, string $method = 'GET', string $ip = self::IP, ?string $ua = null, ?string $cookie = null): Request
	{
		$request = Request::create($path, $method, server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua ?? self::UA, 'HTTP_ACCEPT' => 'text/html']);
		if (null !== $cookie) {
			$request->cookies->set(FirewallChallengeCookie::NAME, $cookie);
		}

		return $request;
	}
}
