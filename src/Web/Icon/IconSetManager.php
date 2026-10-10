<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\IconEntity;
use iikiti\CMS\Entity\IconSetEntity;
use iikiti\CMS\Repository\IconRepository;
use iikiti\CMS\Repository\IconSetRepository;

/**
 * Business logic for admin-managed icon sets. Every icon is sanitised before it is
 * stored, so the stored SVG is always safe to render on the public site.
 */
final class IconSetManager
{
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';
	private const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly IconSetRepository $sets,
		private readonly IconRepository $icons,
		private readonly SvgSanitizer $sanitizer,
	) {
	}

	/** @throws \InvalidArgumentException when the slug or name is malformed or already taken */
	public function createSet(string $slug, string $name): IconSetEntity
	{
		$this->assertSlug($slug);
		$name = $this->assertLabel($name);
		if (null !== $this->sets->findOneBySlug($slug)) {
			throw new \InvalidArgumentException(sprintf('Icon set "%s" already exists.', $slug));
		}

		$set = new IconSetEntity($slug, $name);
		$this->em->persist($set);
		$this->em->flush();

		return $set;
	}

	public function findSet(string $slug): ?IconSetEntity
	{
		return $this->sets->findOneBySlug($slug);
	}

	/** @return list<IconSetEntity> */
	public function allSets(): array
	{
		/** @var list<IconSetEntity> */
		return $this->sets->findBy([], ['slug' => 'ASC']);
	}

	/**
	 * Add or replace an icon in a set. The SVG is sanitised first; a rejected SVG is
	 * never persisted.
	 *
	 * @throws SvgRejectedException  when the SVG is not safe
	 * @throws \InvalidArgumentException when the icon name is malformed
	 */
	public function putIcon(IconSetEntity $set, string $name, string $rawSvg): IconEntity
	{
		if (1 !== preg_match(self::NAME_PATTERN, $name)) {
			throw new \InvalidArgumentException('Icon name must be lowercase letters, digits and dashes.');
		}

		$sanitised = $this->sanitizer->sanitize($rawSvg);

		$existing = $this->icons->findOneInSet($set, $name);
		if (null !== $existing) {
			$this->em->remove($existing);
			$this->em->flush();
		}

		$icon = new IconEntity($set, $name, $sanitised);
		$this->em->persist($icon);
		$this->em->flush();

		return $icon;
	}

	/** @return list<IconEntity> */
	public function iconsIn(IconSetEntity $set): array
	{
		return $this->icons->findBySet($set);
	}

	public function removeIcon(IconSetEntity $set, string $name): bool
	{
		$icon = $this->icons->findOneInSet($set, $name);
		if (null === $icon) {
			return false;
		}
		$this->em->remove($icon);
		$this->em->flush();

		return true;
	}

	public function deleteSet(IconSetEntity $set): void
	{
		$this->em->remove($set);
		$this->em->flush();
	}

	private function assertSlug(string $slug): void
	{
		if (1 !== preg_match(self::SLUG_PATTERN, $slug)) {
			throw new \InvalidArgumentException('Set slug must be lowercase letters, digits and dashes.');
		}
	}

	private function assertLabel(string $name): string
	{
		$trimmed = trim($name);
		if ('' === $trimmed || mb_strlen($trimmed) > 128) {
			throw new \InvalidArgumentException('Set name must be 1 to 128 characters.');
		}

		return $trimmed;
	}
}
