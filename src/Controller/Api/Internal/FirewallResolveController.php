<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Internal;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Security\Firewall\Ip\FirewallIpRuleStore;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;
use iikiti\CMS\Security\Firewall\Policy\FirewallProtectionPolicyResolverInterface;
use iikiti\CMS\Security\Firewall\Policy\RemoteFirewallProtectionResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API-side endpoint consumed by consumer runtimes to fetch the merged protection policy and an
 * authoritative IP decision for the client.
 *
 * Authenticated by HMAC over the exact JSON payload; only requests from configured consumer hosts
 * are answered. The response is never cached by intermediaries.
 */
final readonly class FirewallResolveController
{
	private const MAX_BODY_BYTES = 65536;
	private const MAX_CLOCK_SKEW_SECONDS = 60;

	/** @param list<string> $consumerHosts */
	public function __construct(
		private FirewallProtectionPolicyResolverInterface $policyResolver,
		private EntityManagerInterface $entityManager,
		private FirewallIpRuleStore $ipRules,
		#[Autowire('%kernel.secret%')] private string $secret,
		#[Autowire('%iikiti_consumer_hosts%')] private array $consumerHosts,
	) {
	}

	#[Route(RemoteFirewallProtectionResolver::PATH, name: 'api_internal_firewall_resolve', methods: ['POST'])]
	public function __invoke(Request $request): JsonResponse
	{
		if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
			return new JsonResponse(['error' => 'Invalid request'], 400);
		}

		try {
			$data = $request->toArray();
		} catch (\Throwable) {
			return new JsonResponse(['error' => 'Invalid request'], 400);
		}

		$payload = $data['payload'] ?? null;
		$signature = $data['signature'] ?? null;
		if ('' === trim($this->secret) || !is_array($payload) || !is_string($signature)) {
			return new JsonResponse(['error' => 'Unauthorized'], 401);
		}

		$expected = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $this->secret);
		$time = $payload['time'] ?? null;
		if (!hash_equals($expected, $signature) || !is_int($time) || abs(time() - $time) > self::MAX_CLOCK_SKEW_SECONDS) {
			return new JsonResponse(['error' => 'Unauthorized'], 401);
		}

		if (!$this->isConsumerHost($request->getHost())) {
			return new JsonResponse(['error' => 'Invalid request'], 400);
		}

		$includeSiteRules = $payload['includeSiteRules'] ?? null;
		$siteId = $payload['siteId'] ?? null;
		$host = $payload['host'] ?? null;
		$clientIp = $payload['clientIp'] ?? null;
		if (!is_bool($includeSiteRules) || (null !== $siteId && !is_int($siteId) && !is_string($siteId))) {
			return new JsonResponse(['error' => 'Invalid request'], 400);
		}
		if ((null !== $host && !is_string($host)) || (null !== $clientIp && !is_string($clientIp))) {
			return new JsonResponse(['error' => 'Invalid request'], 400);
		}

		$site = $this->resolveSite($siteId, $host);
		$policy = $this->policyResolver->resolveFor($site, $includeSiteRules);

		$siteScope = $site?->getId();
		$ipMatch = null === $clientIp || null === IpCidrNormalizer::normalizeIp($clientIp)
			? null
			: $this->ipRules->getMatcher($siteScope)->match($clientIp);

		return new JsonResponse(
			[...$policy->toArray(), 'ipMatch' => null === $ipMatch ? null : ['action' => $ipMatch->action]],
			200,
			['Cache-Control' => 'no-store'],
		);
	}

	/** Prefers an explicit host (consumer public host) over the site id, which the consumer may not know. */
	private function resolveSite(mixed $siteId, ?string $host): ?Site
	{
		if (null !== $host && '' !== $host) {
			$matches = $this->entityManager->getRepository(Site::class)->findByDomain(strtolower($host));
			if (1 === count($matches)) {
				return $matches[array_key_first($matches)];
			}
		}

		if (null === $siteId) {
			return null;
		}
		$site = $this->entityManager->getRepository(Site::class)->find($siteId);

		return $site instanceof Site ? $site : null;
	}

	private function isConsumerHost(string $host): bool
	{
		return in_array(strtolower($host), array_map('strtolower', $this->consumerHosts), true);
	}
}
