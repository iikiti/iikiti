<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

enum FirewallAction: string
{
	case Report = 'report';
	case Deny = 'deny';
}
