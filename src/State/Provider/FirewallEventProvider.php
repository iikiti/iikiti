<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\FirewallEventResource;
use iikiti\CMS\Audit\EventSubscriber\FirewallSecurityAuditSubscriber;
use iikiti\CMS\Entity\AuditLogEntry;
use iikiti\CMS\Repository\AuditLogEntryRepository;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Returns a bounded page of recent firewall events. Site routes keep only events whose stored
 * site id matches the request-host site; the scan is capped so the feed stays cheap to render.
 *
 * @implements ProviderInterface<FirewallEventResource>
 */
#[AutoconfigureTag('api_platform.state_provider')]
final readonly class FirewallEventProvider implements ProviderInterface
{
	private const MAX_RESULTS = 100;
	private const SCAN_LIMIT = 500;

	public function __construct(private AuditLogEntryRepository $entries)
	{
	}

	/** @return list<FirewallEventResource> */
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
	{
		$siteFilter = array_key_exists('siteId', $uriVariables)
			? (string) FirewallSiteScope::assertCurrent($uriVariables)->getId()
			: null;

		$recent = $this->entries->findBy(
			['objectType' => FirewallSecurityAuditSubscriber::OBJECT_TYPE],
			['createdAt' => 'DESC'],
			self::SCAN_LIMIT,
		);

		$results = [];
		foreach ($recent as $entry) {
			if (null !== $siteFilter && (string) ($this->siteIdOf($entry) ?? '') !== $siteFilter) {
				continue;
			}
			$results[] = $this->toResource($entry);
			if (count($results) >= self::MAX_RESULTS) {
				break;
			}
		}

		return $results;
	}

	private function siteIdOf(AuditLogEntry $entry): int|string|null
	{
		$details = $entry->getContext()['details'] ?? null;
		$siteId = is_array($details) ? ($details['siteId'] ?? null) : null;

		return is_int($siteId) || is_string($siteId) ? $siteId : null;
	}

	private function toResource(AuditLogEntry $entry): FirewallEventResource
	{
		return new FirewallEventResource(
			id: $entry->getId(),
			action: $entry->getAction(),
			summary: (string) $entry->getSummary(),
			ipAddress: $entry->getIpAddress(),
			requestUri: $entry->getRequestUri(),
			context: $this->sanitizedContext($entry),
			createdAt: $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
		);
	}

	/**
	 * Keeps only the bounded classification fields; drops everything else from the stored context.
	 *
	 * @return array<string,mixed>
	 */
	private function sanitizedContext(AuditLogEntry $entry): array
	{
		$details = $entry->getContext()['details'] ?? [];
		if (!is_array($details)) {
			return [];
		}

		return [
			'action' => $details['action'] ?? null,
			'findings' => $details['findings'] ?? [],
			'deniedBy' => $details['deniedBy'] ?? null,
			'siteId' => $details['siteId'] ?? null,
		];
	}
}
