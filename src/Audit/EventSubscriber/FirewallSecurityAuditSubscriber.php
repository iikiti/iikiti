<?php

declare(strict_types=1);

namespace iikiti\CMS\Audit\EventSubscriber;

use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\FirewallFinding;
use iikiti\CMS\Security\Firewall\FirewallSecurityEvent;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records firewall enforcement (denials) and report-mode findings as audit entries.
 *
 * Clean requests are never recorded. Each (client IP, rule, action) combination is written at most
 * once per {@see self::DEDUPE_WINDOW_SECONDS}, so a sustained L7 flood cannot grow `audit_log_entries`
 * without bound. Only bounded, sanitized fields are stored: never request bodies, headers, cookies,
 * or secrets.
 */
final class FirewallSecurityAuditSubscriber implements EventSubscriberInterface
{
	public const OBJECT_TYPE = 'firewall_event';
	private const DEDUPE_WINDOW_SECONDS = 60;
	private const DEDUPE_KEY_PREFIX = 'fw_audit_v1_';
	private const MAX_FINDINGS_IN_SUMMARY = 3;

	public function __construct(
		private readonly AuditRecorder $recorder,
		#[Autowire(service: 'cache.firewall_ratelimit')]
		private readonly CacheItemPoolInterface $cache,
		private readonly LoggerInterface $logger,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [FirewallSecurityEvent::NAME => 'onFirewallSecurityEvent'];
	}

	public function onFirewallSecurityEvent(FirewallSecurityEvent $event): void
	{
		// Clean requests and pure allows carry no signal worth auditing.
		if ([] === $event->findings) {
			return;
		}

		$clientIp = $event->requestSummary['clientIp'];
		$primary = $event->findings[0];
		$action = FirewallAction::Deny === $event->action ? 'firewall_blocked' : 'firewall_throttled';

		if (!$this->claimDedupeSlot($clientIp, $primary->ruleId, $action)) {
			return;
		}

		try {
			$this->recorder->record(
				$this->summaryFor($event, $clientIp, $action),
				$action,
				self::OBJECT_TYPE,
				null,
				null,
				null,
				$this->detailsFor($event, $clientIp),
			);
		} catch (\Throwable $exception) {
			// Auditing must never change the enforcement outcome of the request.
			$this->logger->warning('Could not record firewall security audit entry.', ['exception' => $exception]);
		}
	}

	/**
	 * Returns true when this (ip, rule, action) has not been audited within the dedupe window.
	 * Storage failures fail open for auditing (recording is skipped) and never block the request.
	 */
	private function claimDedupeSlot(string $clientIp, string $ruleId, string $action): bool
	{
		$key = self::DEDUPE_KEY_PREFIX.hash('sha256', $clientIp."\0".$ruleId."\0".$action);
		try {
			$item = $this->cache->getItem($key);
			if ($item->isHit()) {
				return false;
			}
			$item->set(true);
			$item->expiresAfter(self::DEDUPE_WINDOW_SECONDS);

			return $this->cache->save($item);
		} catch (\Throwable $exception) {
			$this->logger->warning('Firewall audit rate guard unavailable; skipping audit write.', ['exception' => $exception]);

			return false;
		}
	}

	private function summaryFor(FirewallSecurityEvent $event, string $clientIp, string $action): string
	{
		$verb = 'firewall_blocked' === $action ? 'Blocked' : 'Flagged';
		$rules = implode(', ', array_map(
			static fn (FirewallFinding $finding): string => $finding->ruleId,
			array_slice($event->findings, 0, self::MAX_FINDINGS_IN_SUMMARY),
		));

		return sprintf('%s request from %s (%s)', $verb, '' === $clientIp ? 'unknown client' : $clientIp, $rules);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function detailsFor(FirewallSecurityEvent $event, string $clientIp): array
	{
		$findings = [];
		foreach (array_slice($event->findings, 0, self::MAX_FINDINGS_IN_SUMMARY) as $finding) {
			$findings[] = ['ruleId' => $finding->ruleId, 'action' => $finding->action->value];
		}

		return [
			'action' => $event->action->value,
			'findings' => $findings,
			'ipAddress' => $clientIp,
			'method' => substr($event->requestSummary['method'], 0, 16),
			'path' => substr($event->requestSummary['path'], 0, 512),
			'siteId' => $event->requestSummary['siteId'],
			'deniedBy' => $event->stageMetadata['denied_by'] ?? null,
		];
	}
}
