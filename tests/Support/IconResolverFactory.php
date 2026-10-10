<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Support;

use iikiti\CMS\Repository\IconRepository;
use iikiti\CMS\Repository\IconSetRepository;
use iikiti\CMS\Web\Icon\IconResolver;
use iikiti\CMS\Web\Icon\LucideIconSet;

/**
 * Builds an {@see IconResolver} over the bundled Lucide set with no stored icon sets,
 * so unit tests exercise icon rendering without a database.
 */
final class IconResolverFactory
{
	public static function bundledOnly(): IconResolver
	{
		return new IconResolver(
			new LucideIconSet(),
			new EmptyIconSetRepository(),
			new EmptyIconRepository(),
		);
	}
}

/** Returns no stored sets; `findBy`/`findOneBy` are overridden so no database is touched. */
final class EmptyIconSetRepository extends IconSetRepository
{
	public function __construct()
	{
		// Deliberately skips the Doctrine constructor: there is no database in unit tests.
	}

	public function findOneBySlug(string $slug): null
	{
		return null;
	}

	/**
	 * @return list<never>
	 *
	 * @phpstan-pure
	 */
	public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
	{
		return [];
	}
}

/** Returns no stored icons. */
final class EmptyIconRepository extends IconRepository
{
	public function __construct()
	{
		// Deliberately skips the Doctrine constructor: there is no database in unit tests.
	}

	/** @return list<never> */
	public function findBySet(\iikiti\CMS\Entity\IconSetEntity $set): array
	{
		return [];
	}

	public function findOneInSet(\iikiti\CMS\Entity\IconSetEntity $set, string $name): null
	{
		return null;
	}
}
