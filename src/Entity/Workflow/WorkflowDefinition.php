<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Workflow;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\Workflow\WorkflowDefinitionRepository;

#[ORM\Entity(repositoryClass: WorkflowDefinitionRepository::class)]
#[ORM\Table(name: 'workflow_definitions')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_definition_owner_key', columns: ['owner_type', 'owner_key', 'workflow_key'])]
class WorkflowDefinition
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT)]
	private int|string|null $id = null;

	#[ORM\Column(name: 'workflow_key', type: Types::STRING, length: 128)]
	private string $key;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $name;

	#[ORM\Column(name: 'owner_type', type: Types::STRING, length: 16)]
	private string $ownerType;

	#[ORM\Column(name: 'owner_key', type: Types::STRING, length: 128)]
	private string $ownerKey;

	#[ORM\Column(name: 'current_version', type: Types::INTEGER, options: ['default' => 1])]
	private int $currentVersion = 1;

	#[ORM\Version]
	#[ORM\Column(name: 'lock_version', type: Types::INTEGER, options: ['default' => 1])]
	private int $lockVersion = 1;

	#[ORM\Column(name: 'is_enabled', type: Types::BOOLEAN, options: ['default' => true])]
	private bool $enabled = true;

	#[ORM\Column(name: 'is_archived', type: Types::BOOLEAN, options: ['default' => false])]
	private bool $archived = false;

	/** @var Collection<int,WorkflowDefinitionSiteAssignment> */
	#[ORM\OneToMany(mappedBy: 'definition', targetEntity: WorkflowDefinitionSiteAssignment::class, cascade: ['persist'], orphanRemoval: true)]
	private Collection $siteAssignments;

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	public function __construct(string $key, string $name, string $ownerType, string $ownerKey)
	{
		$this->setKey($key);
		$this->setName($name);
		$this->setOwner($ownerType, $ownerKey);
		$this->siteAssignments = new ArrayCollection();
		$this->createdAt = new \DateTimeImmutable();
		$this->updatedAt = $this->createdAt;
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function setKey(string $key): static
	{
		if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $key)) {
			throw new \InvalidArgumentException('Workflow key must be a lowercase namespaced identifier.');
		}
		$this->key = $key;
		$this->touch();

		return $this;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): static
	{
		$name = trim($name);
		if ('' === $name || mb_strlen($name) > 128) {
			throw new \InvalidArgumentException('Workflow name must contain 1–128 characters.');
		}
		$this->name = $name;
		$this->touch();

		return $this;
	}

	public function getOwnerType(): string
	{
		return $this->ownerType;
	}

	public function getOwnerKey(): string
	{
		return $this->ownerKey;
	}

	public function setOwner(string $ownerType, string $ownerKey): static
	{
		if (!in_array($ownerType, ['core', 'site', 'plugin'], true) || '' === trim($ownerKey) || mb_strlen($ownerKey) > 128) {
			throw new \InvalidArgumentException('Workflow owner must be core, site, or plugin with a valid owner key.');
		}
		$this->ownerType = $ownerType;
		$this->ownerKey = trim($ownerKey);
		$this->touch();

		return $this;
	}

	public function getCurrentVersion(): int
	{
		return $this->currentVersion;
	}

	public function advanceVersion(): int
	{
		++$this->currentVersion;
		$this->touch();

		return $this->currentVersion;
	}

	public function isEnabled(): bool
	{
		return $this->enabled && !$this->archived;
	}

	public function isArchived(): bool
	{
		return $this->archived;
	}

	public function archive(): void
	{
		$this->enabled = false;
		$this->archived = true;
		$this->touch();
	}

	/** @return Collection<int,Site> */
	public function getSites(): Collection
	{
		$sites = [];
		foreach ($this->siteAssignments as $assignment) {
			$sites[] = $assignment->getSite();
		}

		return new ArrayCollection($sites);
	}

	public function hasSite(Site $site): bool
	{
		foreach ($this->siteAssignments as $assignment) {
			if ($assignment->getSite() === $site || $assignment->getSite()->getId() === $site->getId()) {
				return true;
			}
		}

		return false;
	}

	public function assignToSite(Site $site): void
	{
		if (!$this->hasSite($site)) {
			$this->siteAssignments->add(new WorkflowDefinitionSiteAssignment($this, $site));
			$this->touch();
		}
	}

	private function touch(): void
	{
		$this->updatedAt = new \DateTimeImmutable();
	}
}
