<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Object;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\Attribute\DbObjectDiscriminator;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\DigitalAssetTagRepository;

/**
 * Reusable tag for classifying and searching digital assets.
 *
 * Stored as a global DbObject (`SITE_SPECIFIC = false`, `site_id = 0`) and
 * uniquely identified within a namespace by `(tag_namespace, tag_slug)`.
 */
#[ORM\Entity(repositoryClass: DigitalAssetTagRepository::class)]
#[ORM\Table(name: 'object_lookup')]
#[DbObjectDiscriminator('digitalassettag')]
class DigitalAssetTag extends DbObject
{
	public const SITE_SPECIFIC = false;
	public const DEFAULT_NAMESPACE = 'asset';

	#[ORM\Column(name: 'tag_name', type: Types::STRING, length: 128, nullable: true)]
	private ?string $name = null;

	#[ORM\Column(name: 'tag_slug', type: Types::STRING, length: 128, nullable: true)]
	private ?string $slug = null;

	#[ORM\Column(name: 'tag_namespace', type: Types::STRING, length: 64, nullable: true, options: ['default' => self::DEFAULT_NAMESPACE])]
	private ?string $namespace = self::DEFAULT_NAMESPACE;

	public function __construct(string $name, string $namespace = self::DEFAULT_NAMESPACE, ?string $slug = null)
	{
		$this->setProperties(new ArrayCollection());
		$this->setSiteId(0);
		$this->setCreatorId(0);
		$this->setCreatedDate(new \DateTimeImmutable());

		$this->setName($name);
		$this->setNamespace($namespace);
		$this->setSlug(null !== $slug && '' !== trim($slug) ? $slug : $name);
	}

	public function getName(): string
	{
		return $this->name ?? throw new \LogicException('Tag name is missing.');
	}

	public function setName(string $name): static
	{
		$trimmed = trim($name);
		if ('' === $trimmed) {
			throw new \InvalidArgumentException('Tag name is required.');
		}
		if (mb_strlen($trimmed) > 128) {
			throw new \InvalidArgumentException('Tag name must not exceed 128 characters.');
		}
		$this->name = $trimmed;

		return $this;
	}

	public function getSlug(): string
	{
		return $this->slug ?? throw new \LogicException('Tag slug is missing.');
	}

	public function setSlug(string $slug): static
	{
		$this->slug = self::slugify($slug);

		return $this;
	}

	public function getNamespace(): string
	{
		return $this->namespace ?? self::DEFAULT_NAMESPACE;
	}

	public function setNamespace(string $namespace): static
	{
		$this->namespace = self::normalizeNamespace($namespace);

		return $this;
	}

	public static function slugify(string $value): string
	{
		$normalized = mb_strtolower(trim($value));
		$normalized = preg_replace('/[^a-z0-9]+/u', '-', $normalized) ?? '';
		$normalized = trim($normalized, '-');
		if ('' === $normalized) {
			throw new \InvalidArgumentException('Tag slug must contain at least one alphanumeric character.');
		}

		return substr($normalized, 0, 128);
	}

	public static function normalizeNamespace(string $namespace): string
	{
		$normalized = strtolower(trim($namespace));
		if ('' === $normalized) {
			return self::DEFAULT_NAMESPACE;
		}
		if (strlen($normalized) > 64 || 1 !== preg_match('/^[a-z0-9][a-z0-9._-]*$/', $normalized)) {
			throw new \InvalidArgumentException('Tag namespace must contain only lowercase letters, numbers, dots, underscores, or hyphens.');
		}

		return $normalized;
	}
}
