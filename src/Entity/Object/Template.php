<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\ApiResource\TemplateResource;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\TemplateRepository;

/**
 * A front-end block-editor template: a layout Twig reference, per-region block trees,
 * assignment rules, and settings. Stored as an `objects` row (type discriminator
 * `iikiti\CMS\Entity\Object\Template`).
 */
#[ORM\Entity(repositoryClass: TemplateRepository::class)]
#[ORM\Table(name: 'objects')]
#[ApiResource]
class Template extends DbObject
{
	public const bool SITE_SPECIFIC = true;

	/**
	 * Default layout + regions used when no template object is configured.
	 */
	public const DEFAULT_LAYOUT = 'base/default-block-editor-content.twig';

	/**
	 * @return list<array<string,mixed>>
	 */
	public function getRegions(): array
	{
		$raw = $this->getProperties()->get('regions')?->getValue();

		return is_array($raw) ? array_values($raw) : [];
	}

	/**
	 * @param list<array<string,mixed>> $regions
	 */
	public function setRegions(array $regions): void
	{
		$this->setProperty('regions', $regions);
	}

	public function getLayout(): string
	{
		$value = $this->getProperties()->get('layout')?->getValue();

		return is_string($value) && '' !== $value ? $value : self::DEFAULT_LAYOUT;
	}

	public function setLayout(string $layout): void
	{
		$this->setProperty('layout', $layout);
	}

	/**
	 * @return array<string,list<array<string,mixed>>>|null
	 */
	public function getBlocks(): ?array
	{
		$value = $this->getProperties()->get('blocks')?->getValue();

		return is_array($value) ? $value : null;
	}

	/**
	 * Draft per-region block trees.
	 *
	 * @return array<string,list<array<string,mixed>>>|null
	 */
	public function getBlocksDraft(): ?array
	{
		$value = $this->getProperties()->get('blocks_draft')?->getValue();

		return is_array($value) ? $value : null;
	}

	/**
	 * @param array<string,list<array<string,mixed>>> $tree
	 */
	public function setBlocksDraft(array $tree): void
	{
		$this->setProperty('blocks_draft', $tree);
	}

	/**
	 * Assignment rules: `{ [{rule, config, priority}, ...], ... }`.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function getAssignments(): array
	{
		$value = $this->getProperties()->get('assignments')?->getValue();

		return is_array($value) ? $value : [];
	}

	/**
	 * @param list<array<string,mixed>> $assignments
	 */
	public function setAssignments(array $assignments): void
	{
		$this->setProperty('assignments', $assignments);
	}

	/**
	 * Template-level editor settings (CSS vars, etc.).
	 *
	 * @return array<string,mixed>
	 */
	public function getSettings(): array
	{
		$value = $this->getProperties()->get('settings')?->getValue();

		return is_array($value) ? $value : [];
	}

	public function getDraftVersion(): int
	{
		$value = $this->getProperties()->get('draft_version')?->getValue();

		return is_numeric($value) ? (int) $value : 0;
	}

	public function setDraftVersion(int $version): void
	{
		$this->setProperty('draft_version', $version);
	}

	public function getTitle(): ?string
	{
		$value = $this->getProperties()->get('title')?->getValue();

		return is_string($value) ? $value : null;
	}

	public function toApi(): TemplateResource
	{
		return TemplateResource::fromEntity($this);
	}
}
