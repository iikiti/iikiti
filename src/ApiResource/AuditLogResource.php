<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\Entity\AuditLogEntry;
use iikiti\CMS\State\Provider\AuditLogProvider;

#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/audit-logs',
			name: 'admin_audit_logs_list',
			provider: AuditLogProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class AuditLogResource
{
	public function __construct(
		public ?int $id = null,
		public ?int $userId = null,
		public string $actorType = 'user',
		public string $action = '',
		public string $objectType = '',
	public ?int $objectId = null,
		/** @var array<string,mixed>|null */
		public ?array $beforeState = null,
		/** @var array<string,mixed>|null */
		public ?array $afterState = null,
		/** @var array<string,mixed> */
		public array $context = [],
		public ?string $ipAddress = null,
		public ?string $requestUri = null,
		public ?\DateTimeInterface $createdAt = null,
	) {
	}

	public static function fromEntity(AuditLogEntry $entry): self
	{
		return new self(
			id: $entry->getId(),
			userId: $entry->getUserId(),
			actorType: $entry->getActorType(),
			action: $entry->getAction(),
			objectType: $entry->getObjectType(),
			objectId: $entry->getObjectId(),
			beforeState: $entry->getBeforeState(),
			afterState: $entry->getAfterState(),
			context: $entry->getContext(),
			ipAddress: $entry->getIpAddress(),
			requestUri: $entry->getRequestUri(),
			createdAt: $entry->getCreatedAt(),
		);
	}
}
