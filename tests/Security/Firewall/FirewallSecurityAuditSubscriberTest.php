<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Audit\EventSubscriber\FirewallSecurityAuditSubscriber;
use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\FirewallFinding;
use iikiti\CMS\Security\Firewall\FirewallSecurityEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Verifies that only enforcement and report findings are audited, clean requests are ignored,
 * and identical events are rate-guarded so an L7 flood cannot flood the audit log.
 */
final class FirewallSecurityAuditSubscriberTest extends TestCase
{
	private const IP = '198.51.100.7';

	public function testDenyEventIsRecordedWithSanitizedDetails(): void
	{
		$recorder = $this->recorder();
		$recorder->expects(self::once())->method('record')->with(
			self::stringContains('Blocked request from '.self::IP),
			'firewall_blocked',
			'firewall_event',
			null,
			null,
			null,
			self::callback(static function (array $details): bool {
				return 'deny' === $details['action']
					&& 'core:ip-block' === $details['findings'][0]['ruleId']
					&& self::IP === $details['ipAddress']
					&& !array_key_exists('headers', $details)
					&& !array_key_exists('cookies', $details)
					&& !array_key_exists('body', $details);
			}),
		);

		$this->subscriber($recorder)->onFirewallSecurityEvent($this->event(FirewallAction::Deny, ['core:ip-block']));
	}

	public function testReportFindingIsRecordedAsThrottledFlag(): void
	{
		$recorder = $this->recorder();
		$recorder->expects(self::once())->method('record')->with(
			self::anything(),
			'firewall_throttled',
			'firewall_event',
			null,
			null,
			null,
			self::anything(),
		);

		$this->subscriber($recorder)->onFirewallSecurityEvent($this->event(FirewallAction::Report, ['bot:impersonation']));
	}

	public function testCleanRequestWithNoFindingsIsNeverRecorded(): void
	{
		$recorder = $this->recorder();
		$recorder->expects(self::never())->method('record');

		$this->subscriber($recorder)->onFirewallSecurityEvent($this->event(FirewallAction::Report, []));
	}

	public function testIdenticalIpRuleActionIsDeduplicatedWithinWindow(): void
	{
		$recorder = $this->recorder();
		$recorder->expects(self::once())->method('record');
		$subscriber = $this->subscriber($recorder);

		for ($i = 0; $i < 500; ++$i) {
			$subscriber->onFirewallSecurityEvent($this->event(FirewallAction::Deny, ['core:ip-block']));
		}
	}

	public function testDifferentRulesAreEachAuditedOnce(): void
	{
		$recorder = $this->recorder();
		$recorder->expects(self::exactly(2))->method('record');
		$subscriber = $this->subscriber($recorder);

		$subscriber->onFirewallSecurityEvent($this->event(FirewallAction::Deny, ['core:ip-block']));
		$subscriber->onFirewallSecurityEvent($this->event(FirewallAction::Deny, ['core:rate-limit']));
		$subscriber->onFirewallSecurityEvent($this->event(FirewallAction::Deny, ['core:ip-block']));
	}

	public function testDifferentClientIpsAreAuditedSeparately(): void
	{
		$recorder = $this->recorder();
		$recorder->expects(self::exactly(2))->method('record');
		$subscriber = $this->subscriber($recorder);

		$subscriber->onFirewallSecurityEvent($this->event(FirewallAction::Deny, ['core:ip-block'], '198.51.100.7'));
		$subscriber->onFirewallSecurityEvent($this->event(FirewallAction::Deny, ['core:ip-block'], '198.51.100.8'));
	}

	public function testRecorderFailureDoesNotPropagate(): void
	{
		$recorder = $this->createStub(AuditRecorder::class);
		$recorder->method('record')->willThrowException(new \RuntimeException('database unavailable'));

		$subscriber = $this->subscriber($recorder);
		$subscriber->onFirewallSecurityEvent($this->event(FirewallAction::Deny, ['core:ip-block']));

		// Reaching this line without an exception proves the failure stayed contained.
		$this->expectNotToPerformAssertions();
	}

	public function testSubscribesToFirewallSecurityEvent(): void
	{
		self::assertSame(
			[FirewallSecurityEvent::NAME => 'onFirewallSecurityEvent'],
			FirewallSecurityAuditSubscriber::getSubscribedEvents(),
		);
	}

	private function recorder(): AuditRecorder&\PHPUnit\Framework\MockObject\MockObject
	{
		return $this->createMock(AuditRecorder::class);
	}

	private function subscriber(AuditRecorder $recorder): FirewallSecurityAuditSubscriber
	{
		return new FirewallSecurityAuditSubscriber($recorder, new ArrayAdapter(), new NullLogger());
	}

	/**
	 * @param list<string> $ruleIds
	 */
	private function event(FirewallAction $action, array $ruleIds, string $ip = self::IP): FirewallSecurityEvent
	{
		$findings = array_map(
			static fn (string $ruleId): FirewallFinding => new FirewallFinding($ruleId, 'msg', $action),
			$ruleIds,
		);

		return new FirewallSecurityEvent(
			$findings,
			$action,
			['method' => 'POST', 'path' => '/api/forms/submit', 'siteId' => '1', 'clientIp' => $ip],
			['denied_by' => 'ip_block', 'headers' => 'SECRET-HEADER', 'cookie' => 'SECRET-COOKIE'],
		);
	}
}
