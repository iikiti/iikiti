<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Bot;

use iikiti\CMS\Entity\Object\FirewallTrustedAutomation;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use Symfony\Component\HttpFoundation\Request;

/**
 * Spoof-resistant identity checks for automated clients.
 *
 * Verification is only ever positive when a trusted source (signed CIDR feed, FCrDNS,
 * or a stored automation secret/key) actually confirms the claim. A missing source is
 * "unknown", never "impersonator", so an absent feed cannot produce false positives.
 */
final readonly class FirewallBotVerifier
{
	/** Accepted clock skew for timestamped signatures, in seconds. */
	private const MAX_SIGNATURE_SKEW = 300;
	private const SIGNATURE_HEADERS = ['signature', 'x-bot-signature'];
	private const TIMESTAMP_HEADERS = ['x-timestamp', 'x-signature-timestamp'];

	public function __construct(
		private FirewallBotCidrFeedStore $cidrFeeds,
		private FirewallFcrdnsVerifier $fcrdns,
		private FirewallAutomationSecretCipher $secrets,
	) {
	}

	/**
	 * @param list<FirewallTrustedAutomation> $automations ordered by priority
	 */
	public function findTrustedAutomation(Request $request, string $clientIp, array $automations): ?FirewallTrustedAutomation
	{
		$path = $request->getPathInfo();
		foreach ($automations as $automation) {
			if ($automation->isEnabled() && $this->pathAllowed($automation, $path) && $this->authenticates($automation, $request, $clientIp)) {
				return $automation;
			}
		}

		return null;
	}

	/**
	 * Verifies a claimed known bot. "Impersonated" requires that a verification source
	 * existed and rejected the client; otherwise the claim is merely unverified.
	 *
	 * @param array<string,mixed> $botsConfig
	 *
	 * @return array{verified:bool,impersonated:bool}
	 */
	public function verifyKnownBot(FirewallBotDefinition $bot, string $clientIp, array $botsConfig): array
	{
		$sourceAvailable = false;
		$verified = false;

		if ('cidr_pack' === $bot->verificationMethod && null !== $bot->cidrPackSourceKey && $this->cidrFeeds->hasSource($bot->cidrPackSourceKey)) {
			$sourceAvailable = true;
			$verified = $this->cidrFeeds->matches($bot->cidrPackSourceKey, $clientIp);
		}

		if (!$verified && $this->fcrdnsEnabled($botsConfig, $bot)) {
			$sourceAvailable = true;
			$verified = $this->fcrdns->verify($clientIp, $bot->fcrdnsDomains);
		}

		return ['verified' => $verified, 'impersonated' => $sourceAvailable && !$verified];
	}

	/** @param array<string,mixed> $botsConfig */
	private function fcrdnsEnabled(array $botsConfig, FirewallBotDefinition $bot): bool
	{
		return true === ($botsConfig['fcrdnsEnabled'] ?? false) && [] !== $bot->fcrdnsDomains;
	}

	private function pathAllowed(FirewallTrustedAutomation $automation, string $path): bool
	{
		$prefixes = $automation->getAllowedPaths();
		if ([] === $prefixes) {
			return true;
		}
		foreach ($prefixes as $prefix) {
			if (str_starts_with($path, $prefix)) {
				return true;
			}
		}

		return false;
	}

	private function authenticates(FirewallTrustedAutomation $automation, Request $request, string $clientIp): bool
	{
		return match ($automation->getAuthMode()) {
			'cidr' => $this->matchesCidrs($automation, $clientIp),
			'header_secret' => $this->matchesHeaderSecret($automation, $request),
			'hmac' => $this->matchesHmac($automation, $request),
			'http_signature' => $this->matchesHttpSignature($automation, $request),
			default => false,
		};
	}

	private function matchesCidrs(FirewallTrustedAutomation $automation, string $clientIp): bool
	{
		foreach ($automation->getCidrs() as $cidr) {
			if (IpCidrNormalizer::matches($clientIp, $cidr)) {
				return true;
			}
		}

		return false;
	}

	private function matchesHeaderSecret(FirewallTrustedAutomation $automation, Request $request): bool
	{
		$stored = $automation->getSecretMaterial();
		$presented = $this->headerValue($request, $automation->getHeaderName());
		if (null === $stored || null === $presented || '' === $presented) {
			return false;
		}

		return hash_equals($stored, $this->secrets->hashHeaderSecret($presented));
	}

	private function matchesHmac(FirewallTrustedAutomation $automation, Request $request): bool
	{
		$encrypted = $automation->getSecretMaterial();
		$signature = $this->headerValue($request, $automation->getHeaderName());
		$timestamp = $this->timestamp($request);
		if (null === $encrypted || null === $signature || null === $timestamp) {
			return false;
		}
		$secret = $this->secrets->decryptHmacSecret($encrypted);
		if (null === $secret) {
			return false;
		}

		$presented = strtolower(preg_replace('/^sha256=/i', '', trim($signature)) ?? '');
		$expected = hash_hmac('sha256', $this->signingPayload($request, $timestamp), $secret);

		return hash_equals($expected, $presented);
	}

	private function matchesHttpSignature(FirewallTrustedAutomation $automation, Request $request): bool
	{
		$publicKey = $automation->getPublicKey();
		$timestamp = $this->timestamp($request);
		$signature = $this->httpSignatureValue($request);
		if (null === $publicKey || null === $timestamp || null === $signature) {
			return false;
		}
		$decodedKey = base64_decode($publicKey, true);
		$decodedSignature = base64_decode($signature, true);
		if (false === $decodedKey || false === $decodedSignature || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen($decodedKey)) {
			return false;
		}

		return sodium_crypto_sign_verify_detached($decodedSignature, $this->signingPayload($request, $timestamp), $decodedKey);
	}

	private function signingPayload(Request $request, string $timestamp): string
	{
		return $request->getMethod()."\n".$request->getPathInfo()."\n".$timestamp;
	}

	/** Returns the timestamp when present and within the accepted skew, otherwise null. */
	private function timestamp(Request $request): ?string
	{
		foreach (self::TIMESTAMP_HEADERS as $header) {
			$value = $request->headers->get($header);
			if (null === $value || 1 !== preg_match('/^[0-9]{1,12}$/D', $value)) {
				continue;
			}

			return abs(time() - (int) $value) <= self::MAX_SIGNATURE_SKEW ? $value : null;
		}

		return null;
	}

	private function httpSignatureValue(Request $request): ?string
	{
		foreach (self::SIGNATURE_HEADERS as $header) {
			$value = $request->headers->get($header);
			if (null !== $value && '' !== trim($value)) {
				return trim($value);
			}
		}

		return null;
	}

	private function headerValue(Request $request, string $name): ?string
	{
		$value = $request->headers->get($name);

		return null === $value ? null : trim($value);
	}
}
