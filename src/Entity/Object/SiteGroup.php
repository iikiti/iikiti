<?php

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;

/**
 * General-purpose site group entity.
 *
 * Allows administrators to group sites together for collective management
 * (e.g. "English-speaking sites" or "EU sites"). This is distinct from
 * {@see \iikiti\CMS\Search\Entity\SiteGroup} which is scoped to search
 * configuration.
 *
 * Site groups are global (SITE_SPECIFIC = false) — they can reference sites
 * across multiple applications.
 */
#[ORM\Entity(repositoryClass: \iikiti\CMS\Repository\Object\SiteGroupRepository::class)]
#[ORM\Table(name: 'objects')]
#[ApiResource]
class SiteGroup extends DbObject
{
	public const SITE_SPECIFIC = false;

	public const NAME_KEY = 'name';
	public const LABEL_KEY = 'label';
	public const DESCRIPTION_KEY = 'description';
	public const SYSTEM_KEY = 'is_system';
	public const SITE_IDS_KEY = 'site_ids';

	/**
	 * @return string[]
	 */
	public function getSiteIds(): array
	{
		$value = $this->getProperties()->get(self::SITE_IDS_KEY)?->getValue();

		return is_array($value) ? array_values($value) : [];
	}

	/**
	 * @param array<int,int|string> $siteIds
	 */
	public function setSiteIds(array $siteIds): static
	{
		$this->setProperty(self::SITE_IDS_KEY, array_values($siteIds));

		return $this;
	}

	public function addSiteId(int|string $siteId): static
	{
		$ids = array_map('strval', $this->getSiteIds());
		$siteId = (string) $siteId;
		if (!in_array($siteId, $ids, true)) {
			$ids[] = $siteId;
		}
		$this->setProperty(self::SITE_IDS_KEY, $ids);

		return $this;
	}

	public function removeSiteId(int|string $siteId): static
	{
		$ids = array_map('strval', $this->getSiteIds());
		$siteId = (string) $siteId;
		$ids = array_values(array_filter($ids, static fn (string $id): bool => $id !== $siteId));
		$this->setProperty(self::SITE_IDS_KEY, $ids);

		return $this;
	}

	public function getName(): ?string
	{
		return $this->getProperties()->get(self::NAME_KEY)?->getValue();
	}

	public function setName(string $name): static
	{
		$this->setProperty(self::NAME_KEY, $name);

		return $this;
	}

	public function getLabel(): ?string
	{
		return $this->getProperties()->get(self::LABEL_KEY)?->getValue();
	}

	public function setLabel(string $label): static
	{
		$this->setProperty(self::LABEL_KEY, $label);

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->getProperties()->get(self::DESCRIPTION_KEY)?->getValue();
	}

	public function setDescription(?string $description): void
	{
		$this->setProperty(self::DESCRIPTION_KEY, $description);
	}

	public function isSystem(): bool
	{
		return (bool) $this->getProperties()->get(self::SYSTEM_KEY)?->getValue();
	}

	public function setSystem(bool $isSystem): static
	{
		$this->setProperty(self::SYSTEM_KEY, $isSystem);

		return $this;
	}
}
