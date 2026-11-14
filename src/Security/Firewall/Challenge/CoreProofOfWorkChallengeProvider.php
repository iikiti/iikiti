<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Challenge;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Built-in interstitial: the browser solves a small SHA-256 proof-of-work over a
 * server-signed challenge, then posts it to the verification endpoint, which issues a
 * short-lived clearance cookie. Never served to /api/* (see {@see supports()}).
 */
final readonly class CoreProofOfWorkChallengeProvider implements FirewallChallengeProviderInterface
{
	public const NAME = 'core_pow';
	public const VERIFY_PATH = '/_firewall/challenge/verify';
	public const CHALLENGE_PURPOSE = 'pow';
	public const CHALLENGE_TTL = 300;
	public const MIN_DIFFICULTY = 8;
	public const MAX_DIFFICULTY = 22;
	private const DEFAULT_DIFFICULTY = 16;

	public function __construct(
		private FirewallChallengeToken $tokens,
		private FirewallChallengeCookie $cookies,
		private FirewallChallengePageRenderer $renderer,
	) {
	}

	public function getName(): string
	{
		return self::NAME;
	}

	public function supports(Request $request): bool
	{
		$path = $request->getPathInfo();
		if ('/api' === $path || str_starts_with($path, '/api/')) {
			return false;
		}
		if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
			return false;
		}

		return str_contains((string) $request->headers->get('Accept', ''), 'text/html');
	}

	/** @param array<string,mixed> $botsConfig */
	public function hasValidClearance(Request $request, array $botsConfig): bool
	{
		return $this->cookies->isValid($request);
	}

	/** @param array<string,mixed> $botsConfig */
	public function createChallengeResponse(Request $request, array $botsConfig, string $reason): Response
	{
		$difficulty = self::clampDifficulty($botsConfig['challengeDifficulty'] ?? self::DEFAULT_DIFFICULTY);
		$expiresAt = time() + self::CHALLENGE_TTL;
		$payload = [
			'nonce' => bin2hex(random_bytes(16)),
			'ip' => (string) $request->getClientIp(),
			'uaHash' => FirewallChallengeCookie::userAgentHash($request),
			'exp' => $expiresAt,
			'difficulty' => $difficulty,
			'returnTo' => self::safeReturnPath($request),
		];
		$challenge = $this->tokens->sign($payload, self::CHALLENGE_PURPOSE);

		$response = new Response(
			$this->renderer->render($challenge, $difficulty, $reason, self::VERIFY_PATH),
			Response::HTTP_TOO_MANY_REQUESTS,
			[
				'Content-Type' => 'text/html; charset=UTF-8',
				'Cache-Control' => 'no-store, private',
				'X-Robots-Tag' => 'noindex',
			],
		);
		if ($request->isMethod('HEAD')) {
			$response->setContent('');
		}

		return $response;
	}

	/** Clamps a configured difficulty into the supported bit range. */
	public static function clampDifficulty(mixed $value): int
	{
		$difficulty = is_int($value) ? $value : self::DEFAULT_DIFFICULTY;

		return max(self::MIN_DIFFICULTY, min(self::MAX_DIFFICULTY, $difficulty));
	}

	/**
	 * Only same-origin relative paths may be returned to; anything else falls back to "/".
	 * Protocol-relative ("//host") and backslash forms are rejected because browsers treat
	 * them as external redirects.
	 */
	public static function safeReturnPath(Request $request): string
	{
		$candidate = $request->getRequestUri();
		if (1 !== preg_match('#^/(?![/\\\\])[^\x00-\x1f\x7f]*$#D', $candidate)) {
			return '/';
		}

		return $candidate;
	}
}
