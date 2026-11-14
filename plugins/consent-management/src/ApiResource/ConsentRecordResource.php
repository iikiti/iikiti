<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use iikiti\Extension\ConsentManagement\State\Provider\ConsentRecordProvider;

/**
 * Read-only resource exposing consent records for the admin consent log.
 */
#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/privacy/consent-log',
			name: 'admin_privacy_consent_log_list',
			provider: ConsentRecordProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/privacy/consent-log/{id}',
			name: 'admin_privacy_consent_log_item',
			provider: ConsentRecordProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class ConsentRecordResource
{
	/**
	 * @param array<string,bool>   $categories
	 * @param array<string,mixed>|null $signals
	 * @param array<string,mixed>  $context
	 */
	public function __construct(
		public ?int $id = null,
		public ?int $siteId = null,
		public ?int $userId = null,
		public ?string $sessionId = null,
		public array $categories = [],
		public ?string $legalFramework = null,
		public ?string $geoCountry = null,
		public ?array $signals = null,
		public ?string $proofToken = null,
		public ?string $consentedAt = null,
		public ?string $ipAddress = null,
		public ?string $userAgent = null,
		public ?\DateTimeInterface $createdAt = null,
		public array $context = [],
	) {}

	public static function fromEntity(\iikiti\Extension\ConsentManagement\Entity\ConsentRecord $record): self
	{
		return new self(
			id: $record->getId() === null ? null : (int) $record->getId(),
			siteId: $record->getSiteId() === null ? null : (int) $record->getSiteId(),
			userId: $record->getUserId() === null ? null : (int) $record->getUserId(),
			sessionId: $record->getSessionId(),
			categories: $record->getCategories(),
			legalFramework: $record->getLegalFramework(),
			geoCountry: $record->getGeoCountry(),
			signals: $record->getSignals(),
			proofToken: $record->getProofToken(),
			consentedAt: $record->getConsentedAt()?->format(\DateTimeInterface::ATOM),
			ipAddress: $record->getIpAddress(),
			userAgent: $record->getUserAgent(),
			createdAt: $record->getCreatedDate(),
			context: [],
		);
	}
}
