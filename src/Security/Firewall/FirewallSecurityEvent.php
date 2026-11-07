<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Symfony\Contracts\EventDispatcher\Event;

final class FirewallSecurityEvent extends Event
{
	public const NAME = 'iikiti.security';

	/**
	 * @param list<FirewallFinding>                                                   $findings
	 * @param array{method:string,path:string,siteId:int|string|null,clientIp:string} $requestSummary
	 */
	public function __construct(
		public readonly array $findings,
		public readonly FirewallAction $action,
		public readonly array $requestSummary,
	) {
	}
}
