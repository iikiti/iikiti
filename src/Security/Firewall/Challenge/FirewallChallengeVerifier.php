<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Challenge;

use Symfony\Component\HttpFoundation\Request;

/**
 * Validates a submitted proof-of-work solution against its signed challenge.
 *
 * Every check is cheap and fails closed: signature, expiry, IP and UA binding, difficulty
 * bounds, and the SHA-256 leading-zero requirement are all enforced before clearance.
 */
final readonly class FirewallChallengeVerifier
{
	private const MAX_SOLUTION_LENGTH = 20;

	public function __construct(
		private FirewallChallengeToken $tokens,
	) {
	}

	/**
	 * Returns the validated challenge payload when the solution is acceptable, otherwise null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function verify(Request $request, string $challengeToken, string $solution): ?array
	{
		$payload = $this->tokens->verify($challengeToken, CoreProofOfWorkChallengeProvider::CHALLENGE_PURPOSE);
		if (null === $payload || !$this->isBoundToRequest($payload, $request) || !$this->isUnexpired($payload)) {
			return null;
		}

		$difficulty = $payload['difficulty'] ?? null;
		if (!is_int($difficulty) || $difficulty !== CoreProofOfWorkChallengeProvider::clampDifficulty($difficulty)) {
			return null;
		}
		if (!$this->isValidSolution($challengeToken, $solution, $difficulty)) {
			return null;
		}

		return $payload;
	}

	/** Returns the validated local redirect target from the payload, defaulting to "/". */
	/** @param array<string,mixed> $payload */
	public function returnPath(array $payload): string
	{
		$returnTo = $payload['returnTo'] ?? null;
		if (!is_string($returnTo) || 1 !== preg_match('#^/(?![/\\\\])[^\x00-\x1f\x7f]*$#D', $returnTo)) {
			return '/';
		}

		return $returnTo;
	}

	/** @param array<string,mixed> $payload */
	private function isBoundToRequest(array $payload, Request $request): bool
	{
		return ($payload['ip'] ?? null) === (string) $request->getClientIp()
			&& ($payload['uaHash'] ?? null) === FirewallChallengeCookie::userAgentHash($request);
	}

	/** @param array<string,mixed> $payload */
	private function isUnexpired(array $payload): bool
	{
		$expiresAt = $payload['exp'] ?? null;
		if (!is_int($expiresAt)) {
			return false;
		}

		// Reject tokens issued further out than the TTL allows, not just expired ones.
		return $expiresAt > time() && $expiresAt <= time() + CoreProofOfWorkChallengeProvider::CHALLENGE_TTL;
	}

	private function isValidSolution(string $challenge, string $solution, int $difficulty): bool
	{
		if (1 !== preg_match('/^[0-9]{1,'.self::MAX_SOLUTION_LENGTH.'}$/D', $solution)) {
			return false;
		}

		$digest = hash('sha256', $challenge.':'.$solution, true);

		return self::leadingZeroBits($digest) >= $difficulty;
	}

	private static function leadingZeroBits(string $bytes): int
	{
		$bits = 0;
		$length = strlen($bytes);
		for ($i = 0; $i < $length; $i++) {
			$byte = ord($bytes[$i]);
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
}
