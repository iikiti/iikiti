<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Policy;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Entity\Object\Site;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Consumer-mode protection policy source. Asks the API runtime for the merged policy over an
 * HMAC-signed internal request, so the consumer never needs the Application/Site tables.
 *
 * The request carries the public host and the client IP so the API can evaluate IP rules and
 * return an `ipMatch` short-circuit alongside the policy. Mirrors
 * {@see \iikiti\CMS\Service\RemoteResponseHeaderResolver}: the signature covers the exact JSON
 * payload, and the API rejects stale timestamps (more than 60 seconds of skew).
 */
final readonly class RemoteFirewallProtectionResolver implements FirewallProtectionPolicyResolverInterface
{
	public const PATH = '/api/internal/firewall/resolve';

	public function __construct(
		private BusinessApiClient $api,
		#[Autowire('%kernel.secret%')] private string $secret,
	) {
	}

	/**
	 * Resolves the policy for a site scope. When `$clientIp` is supplied the API also evaluates IP
	 * rules; a non-null `ipMatch` in the result is authoritative and short-circuits Stage 1.
	 */
	public function resolveFor(?Site $site, bool $includeSiteRules, ?string $host = null, ?string $clientIp = null): FirewallResolvedPolicy
	{
		if ('' === trim($this->secret)) {
			throw new \RuntimeException('A shared APP_SECRET is required for firewall policy resolution.');
		}

		$payload = [
			'siteId' => $includeSiteRules ? $site?->getId() : null,
			'includeSiteRules' => $includeSiteRules,
			'host' => $host,
			'clientIp' => $clientIp,
			'time' => time(),
		];
		$json = json_encode($payload, JSON_THROW_ON_ERROR);
		$result = $this->api->request('POST', self::PATH, body: [
			'payload' => $payload,
			'signature' => hash_hmac('sha256', $json, $this->secret),
		]);

		if (!is_array($result['rateLimits'] ?? null) || !is_array($result['dos'] ?? null) || !is_array($result['bots'] ?? null)) {
			throw new \UnexpectedValueException('Invalid firewall policy resolver result.');
		}

		return new FirewallResolvedPolicy(
			$result['rateLimits'],
			$result['dos'],
			$result['bots'],
			is_array($result['ipMatch'] ?? null) ? $result['ipMatch'] : null,
		);
	}
}
