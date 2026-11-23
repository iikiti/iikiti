<?php

declare(strict_types=1);

namespace iikiti\CMS\Audit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Allows plugins to register custom audit log action names (max 32 chars,
 * lowercase alphanumeric with underscores) without editing core {@see \iikiti\CMS\Entity\AuditLogEntry::ACTIONS}.
 */
#[AutoconfigureTag('iikiti.audit.action_provider')]
interface AuditActionProviderInterface
{
	/**
	 * @return list<string>
	 */
	public function getAuditActions(): array;
}
