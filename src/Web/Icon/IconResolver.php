<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

use iikiti\CMS\Repository\IconRepository;
use iikiti\CMS\Repository\IconSetRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves an icon block's `name` to the icon set that holds it.
 *
 * - `box`            -> bundled icon set, icon `box` (existing content keeps working)
 * - `brand/logo`     -> admin-managed set with slug `brand`, icon `logo`
 *
 * A name is never resolved against a set the request did not ask for, and an unknown
 * set or icon resolves to null so the renderer emits nothing.
 */
final class IconResolver
{
	/** @var list<IconSet> */
	private readonly array $bundledSets;

	/**
	 * @param iterable<IconSet>|IconSet $bundledSets
	 */
	public function __construct(
		#[AutowireIterator('iikiti.cms.icon_set')]
		iterable|IconSet $bundledSets,
		private readonly IconSetRepository $sets,
		private readonly IconRepository $icons,
	) {
		$sets = $bundledSets instanceof IconSet ? [$bundledSets] : [...$bundledSets];
		$this->bundledSets = array_values($sets);
	}

	/**
	 * @return array{set: IconSet, name: string}|null
	 */
	public function resolve(string $reference): ?array
	{
		$slash = strpos($reference, '/');
		if (false === $slash) {
			foreach ($this->bundledSets as $bundled) {
				if ($bundled->has($reference)) {
					return ['set' => $bundled, 'name' => $reference];
				}
			}

			return null;
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
		$references = [];
		foreach ($this->bundledSets as $bundled) {
			array_push($references, ...$bundled->names());
		}
		foreach ($this->sets->findBy([], ['slug' => 'ASC']) as $set) {
			$stored = new StoredIconSet($set, $this->icons);
			foreach ($stored->names() as $name) {
				$references[] = $set->getSlug().'/'.$name;
			}
		}

		return array_values(array_unique($references));
	}
}
