<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Challenge;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Issues and validates the IP- and User-Agent-bound clearance cookie.
 */
final readonly class FirewallChallengeCookie
{
	public const NAME = 'iikiti_fw_clearance';
	private const PURPOSE = 'clearance';
	private const DEFAULT_TTL = 1800;

	public function __construct(
		private FirewallChallengeToken $tokens,
	) {
	}

	public function issue(Request $request, int $ttl = self::DEFAULT_TTL): Cookie
	{
		$payload = [
			'ip' => (string) $request->getClientIp(),
			'uaHash' => self::userAgentHash($request),
			'exp' => time() + max(60, $ttl),
		];

		return Cookie::create(self::NAME)
			->withValue($this->tokens->sign($payload, self::PURPOSE))
			->withExpires(time() + max(60, $ttl))
			->withPath('/')
			->withHttpOnly(true)
			->withSameSite(Cookie::SAMESITE_LAX)
			->withSecure($request->isSecure());
	}

	public function isValid(Request $request): bool
	{
		$token = $request->cookies->get(self::NAME);
		if (!is_string($token) || '' === $token) {
			return false;
		}

		$payload = $this->tokens->verify($token, self::PURPOSE);
		if (null === $payload) {
			return false;
		}

		return ($payload['ip'] ?? null) === (string) $request->getClientIp()
			&& ($payload['uaHash'] ?? null) === self::userAgentHash($request)
			&& is_int($payload['exp'] ?? null)
			&& $payload['exp'] > time();
	}

	public static function userAgentHash(Request $request): string
	{
		return hash('sha256', (string) $request->headers->get('User-Agent', ''));
	}
}
