<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\Entity\AuditLogEntry;
use iikiti\CMS\State\Provider\AuditLogItemProvider;
use iikiti\CMS\State\Provider\AuditLogProvider;

#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/audit-logs',
			name: 'admin_audit_logs_list',
			provider: AuditLogProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/audit-logs/{id}',
			name: 'admin_audit_logs_item',
			provider: AuditLogItemProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class AuditLogResource
{
	/**
	 * @param list<AuditLogResource>                $subEvents
	 * @param array<string,mixed>|null              $beforeState
	 * @param array<string,mixed>|null              $afterState
	 * @param array<string,mixed>                   $context
	 */
	public function __construct(
		public ?int $id = null,
		public ?int $userId = null,
		public ?string $username = null,
		public ?string $summary = null,
		public ?int $parentId = null,
		public string $actorType = 'user',
		public string $action = '',
		public string $objectType = '',
		public ?int $objectId = null,
		public ?array $beforeState = null,
		public ?array $afterState = null,
		public array $context = [],
		public ?string $ipAddress = null,
		public ?string $requestUri = null,
		public ?\DateTimeInterface $createdAt = null,
		public array $subEvents = [],
	) {
	}

	/**
	 * Builds the resource for a row. Sub-events are included only when
	 * requested, so list responses stay small.
	 */
	public static function fromEntity(AuditLogEntry $entry, bool $withSubEvents = false): self
	{
		$subEvents = [];
		if ($withSubEvents) {
			foreach ($entry->getSubEvents() as $sub) {
				$subEvents[] = self::fromEntity($sub);
			}
		}

		return new self(
			id: $entry->getId() === null ? null : (int) $entry->getId(),
			userId: $entry->getUserId() === null ? null : (int) $entry->getUserId(),
			username: $entry->getUser()?->getUserIdentifier() ?? $entry->getUsernameSnapshot(),
			summary: $entry->getSummary() ?? self::fallbackSummary($entry),
			parentId: $entry->getParent()?->getId() === null ? null : (int) $entry->getParent()->getId(),
			actorType: $entry->getActorType(),
			action: $entry->getAction(),
			objectType: $entry->getObjectType(),
			objectId: $entry->getObjectId() === null ? null : (int) $entry->getObjectId(),
			beforeState: $entry->getBeforeState(),
			afterState: $entry->getAfterState(),
			context: $entry->getContext(),
			ipAddress: $entry->getIpAddress(),
			requestUri: $entry->getRequestUri(),
			createdAt: $entry->getCreatedAt(),
			subEvents: $subEvents,
		);
	}

	/**
	 * Entries written before summaries existed have none; describe them from
	 * their action and object so the list stays readable.
	 */
	private static function fallbackSummary(AuditLogEntry $entry): string
	{
		return sprintf('%s %s', ucfirst(str_replace('_', ' ', $entry->getAction())), $entry->getObjectType());
	}
}
