<?php

namespace iikiti\CMS\Entity\Plugin;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\Plugin\PluginRecordRepository;

/**
 * Audit and version record for an installed plugin.
 *
 * A row with `site_id = NULL` is a system-level install record. Per-site
 * enablement itself is stored in each site's configuration (`plugins.active`);
 * this table records what was installed, from where, and when, which is used for
 * update checks and auditing.
 */
#[ORM\Entity(repositoryClass: PluginRecordRepository::class)]
#[ORM\Table(name: 'plugin_registry')]
#[ORM\UniqueConstraint(name: 'uniq_plugin_site', columns: ['slug', 'site_id'])]
class PluginRecord
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $slug;

	#[ORM\Column(name: 'site_id', type: Types::STRING, length: 64, nullable: true)]
	private ?string $siteId = null;

	#[ORM\Column(type: Types::STRING, length: 32)]
	private string $version;

	#[ORM\Column(type: Types::STRING, length: 32)]
	private string $source = 'manual';

	#[ORM\Column(type: Types::STRING, length: 32)]
	private string $state = 'published';

	#[ORM\Column(name: 'installed_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $installedAt;

	public function __construct(
		string $slug,
		string $version,
		?string $siteId = null,
		string $source = 'manual',
		string $state = 'published',
	) {
		$this->slug = $slug;
		$this->version = $version;
		$this->siteId = $siteId;
		$this->source = $source;
		$this->state = $state;
		$this->installedAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getSlug(): string
	{
		return $this->slug;
	}

	public function getSiteId(): ?string
	{
		return $this->siteId;
	}

	public function getVersion(): string
	{
		return $this->version;
	}

	public function setVersion(string $version): void
	{
		$this->version = $version;
	}

	public function getSource(): string
	{
		return $this->source;
	}

	public function setSource(string $source): void
	{
		$this->source = $source;
	}

	public function getState(): string
	{
		return $this->state;
	}

	public function setState(string $state): void
	{
		$this->state = $state;
	}

	public function getInstalledAt(): \DateTimeImmutable
	{
		return $this->installedAt;
	}

	public function touchInstalledAt(): void
	{
		$this->installedAt = new \DateTimeImmutable();
	}
}
