<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

use iikiti\CMS\Repository\IconRepository;
use iikiti\CMS\Repository\IconSetRepository;

/**
 * Resolves an icon block's `name` to the icon set that holds it.
 *
 * - `box`            -> bundled Lucide set, icon `box` (existing content keeps working)
 * - `brand/logo`     -> admin-managed set with slug `brand`, icon `logo`
 *
 * A name is never resolved against a set the request did not ask for, and an unknown
 * set or icon resolves to null so the renderer emits nothing.
 */
final class IconResolver
{
	public function __construct(
		private readonly LucideIconSet $bundled,
		private readonly IconSetRepository $sets,
		private readonly IconRepository $icons,
	) {
	}

	/**
	 * @return array{set: IconSet, name: string}|null
	 */
	public function resolve(string $reference): ?array
	{
		$slash = strpos($reference, '/');
		if (false === $slash) {
			return $this->bundled->has($reference) ? ['set' => $this->bundled, 'name' => $reference] : null;
		}

		$slug = substr($reference, 0, $slash);
		$name = substr($reference, $slash + 1);
		$entity = $this->sets->findOneBySlug($slug);
		if (null === $entity) {
			return null;
		}

		$stored = new StoredIconSet($entity, $this->icons);

		return $stored->has($name) ? ['set' => $stored, 'name' => $name] : null;
	}

	/**
	 * Every selectable reference: bundled names plus `slug/name` for every stored icon.
	 *
	 * @return list<string>
	 */
	public function allReferences(): array
	{
		$references = $this->bundled->names();
		foreach ($this->sets->findBy([], ['slug' => 'ASC']) as $set) {
			$stored = new StoredIconSet($set, $this->icons);
			foreach ($stored->names() as $name) {
				$references[] = $set->getSlug().'/'.$name;
			}
		}

		return $references;
	}
}
