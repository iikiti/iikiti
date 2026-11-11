<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\Site;

/** Operational key metadata; this is not a user-addressable CMS object. */
#[ORM\Entity]
#[ORM\Table(name: 'secret_site_keys')]
#[ORM\UniqueConstraint(name: 'uniq_secret_site_keys_site_id', columns: ['site_id'])]
class SecretSiteKey
{
	#[ORM\Id]
	#[ORM\Column(name: 'scope_key', type: Types::STRING, length: 64)]
	private string $scopeKey;

	#[ORM\ManyToOne(targetEntity: Site::class)]
	#[ORM\JoinColumn(name: 'site_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
	private ?Site $site;

	#[ORM\Column(name: 'wrapped_master_key', type: Types::TEXT)]
	private string $wrappedMasterKey;

	#[ORM\Column(name: 'wrapping_key_id', type: Types::STRING, length: 64)]
	private string $wrappingKeyId;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	public function __construct(string $scopeKey, ?Site $site, string $wrappedMasterKey, string $wrappingKeyId)
	{
		$this->scopeKey = $scopeKey;
		$this->site = $site;
		$this->wrappedMasterKey = $wrappedMasterKey;
		$this->wrappingKeyId = $wrappingKeyId;
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function getScopeKey(): string
	{
		return $this->scopeKey;
	}

	public function getSite(): ?Site
	{
		return $this->site;
	}

	public function getWrappedMasterKey(): string
	{
		return $this->wrappedMasterKey;
	}

	public function getWrappingKeyId(): string
	{
		return $this->wrappingKeyId;
	}

	public function rewrap(string $wrappedMasterKey, string $wrappingKeyId): void
	{
		$this->wrappedMasterKey = $wrappedMasterKey;
		$this->wrappingKeyId = $wrappingKeyId;
		$this->updatedAt = new \DateTimeImmutable();
	}
}
