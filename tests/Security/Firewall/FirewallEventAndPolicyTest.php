<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Security\Firewall\FirewallAction;
use iikiti\CMS\Security\Firewall\FirewallFinding;
use iikiti\CMS\Security\Firewall\FirewallSecurityEvent;
use iikiti\CMS\Security\Firewall\FirewallSitePolicyStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class FirewallEventAndPolicyTest extends TestCase
{
	public function testDispatchesTheNamedEventWithSanitizedFindingsAndAction(): void
	{
		$dispatcher = new EventDispatcher();
		$received = null;
		$dispatcher->addListener(FirewallSecurityEvent::NAME, static function (FirewallSecurityEvent $event) use (&$received): void {
			$received = $event;
		});
		$findings = [new FirewallFinding('core:owasp-sqli', 'Suspicious SQL injection syntax', FirewallAction::Report)];
		$summary = ['method' => 'GET', 'path' => '/search', 'siteId' => 2, 'clientIp' => '127.0.0.1'];

		$dispatcher->dispatch(new FirewallSecurityEvent($findings, FirewallAction::Report, $summary), FirewallSecurityEvent::NAME);

		self::assertInstanceOf(FirewallSecurityEvent::class, $received);
		self::assertSame($findings, $received->findings);
		self::assertSame($summary, $received->requestSummary);
		self::assertSame(FirewallAction::Report, $received->action);
	}

	public function testSitePolicyIsStoredAsSiteObjectProperty(): void
	{
		$site = new Site();
		$site->setProperties(new ArrayCollection());
		$store = new FirewallSitePolicyStore();
		$rules = [[
			'id' => 'site-check',
			'name' => 'Site rule',
			'enabled' => true,
			'action' => 'report',
			'priority' => 0,
			'conditions' => ['condition' => 'request.match', 'options' => ['target' => 'path', 'operator' => 'contains', 'value' => '/probe']],
		]];

		$store->setRules($site, $rules);

		self::assertSame($rules, $store->getRules($site));
	}
}
