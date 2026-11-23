<?php

declare(strict_types=1);

namespace iikiti\CMS\Audit;

use iikiti\CMS\Entity\AuditLogEntry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects core and plugin-registered audit action identifiers.
 */
final class AuditActionRegistry
{
	/** @var array<string, true> */
	private array $actions = [];

	/**
	 * @param iterable<AuditActionProviderInterface> $providers
	 */
	public function __construct(
		#[AutowireIterator('iikiti.audit.action_provider')]
		iterable $providers = [],
	) {
		foreach (AuditLogEntry::ACTIONS as $action) {
			$this->actions[$action] = true;
		}
		foreach ($providers as $provider) {
			foreach ($provider->getAuditActions() as $action) {
				$normalized = strtolower(trim($action));
				if (1 !== preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $normalized)) {
					throw new \InvalidArgumentException(sprintf('Invalid plugin audit action "%s": must match ^[a-z][a-z0-9_]{1,31}$.', $action));
				}
				$this->actions[$normalized] = true;
			}
		}
	}

	public function isValid(string $action): bool
	{
		return isset($this->actions[$action]);
	}

	/**
	 * @return list<string>
	 */
	public function all(): array
	{
		return array_keys($this->actions);
	}
}
