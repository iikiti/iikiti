<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\Object\ShellRepository;

/**
 * A global layout shell: a header, footer, sidebar or dialog. Shells are not
 * owned by a template. Each one is shown on requests where one of its
 * `display_rules` matches (see {@see \iikiti\CMS\Web\Template\ShellResolver}).
 *
 * Stored as an `objects` row (type discriminator `Shell`).
 */
#[ORM\Entity(repositoryClass: ShellRepository::class)]
#[ORM\Table(name: 'objects')]
#[ApiResource]
class Shell extends DbObject
{
	public const ROLE_HEADER = 'header';
	public const ROLE_FOOTER = 'footer';
	public const ROLE_ASIDE = 'aside';
	public const ROLE_DIALOG = 'dialog';

	/** @var list<string> */
	public const ROLES = [self::ROLE_HEADER, self::ROLE_FOOTER, self::ROLE_ASIDE, self::ROLE_DIALOG];

	public function getRole(): string
	{
		$value = $this->getProperties()->get('role')?->getValue();

		return is_string($value) ? $value : '';
	}

	public function setRole(string $role): void
	{
		if (!in_array($role, self::ROLES, true)) {
			throw new \InvalidArgumentException(sprintf('Unknown shell role "%s".', $role));
		}
		$this->setProperty('role', $role);
	}

	public function getName(): string
	{
		$value = $this->getProperties()->get('name')?->getValue();

		return is_string($value) ? $value : '';
	}

	public function setName(string $name): void
	{
		$this->setProperty('name', $name);
	}

	public function getPriority(): int
	{
		$value = $this->getProperties()->get('priority')?->getValue();

		return is_numeric($value) ? (int) $value : 0;
	}

	public function setPriority(int $priority): void
	{
		$this->setProperty('priority', $priority);
	}

	public function isEnabled(): bool
	{
		$value = $this->getProperties()->get('enabled')?->getValue();

		return null === $value ? true : (bool) $value;
	}

	public function setEnabled(bool $enabled): void
	{
		$this->setProperty('enabled', $enabled);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function getDisplayRules(): array
	{
		$value = $this->getProperties()->get('display_rules')?->getValue();

		return is_array($value) ? array_values($value) : [];
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 */
	public function setDisplayRules(array $rules): void
	{
		$this->setProperty('display_rules', array_values($rules));
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function getBlocks(): array
	{
		$value = $this->getProperties()->get('blocks')?->getValue();

		return is_array($value) ? array_values($value) : [];
	}

	/**
	 * @param list<array<string,mixed>> $blocks
	 */
	public function setBlocks(array $blocks): void
	{
		$this->setProperty('blocks', array_values($blocks));
	}
}
