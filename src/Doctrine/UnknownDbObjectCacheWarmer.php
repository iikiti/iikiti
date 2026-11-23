<?php

declare(strict_types=1);

namespace iikiti\CMS\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Ensures the unknown-discriminator proxy directory exists and warms DbObject
 * metadata in API mode so any retained plugin rows have generated fallback
 * classes ready before requests arrive.
 */
final class UnknownDbObjectCacheWarmer implements CacheWarmerInterface
{
	public function __construct(
		private readonly UnknownDbObjectClassGenerator $generator,
		private readonly EntityManagerInterface $entityManager,
		#[Autowire('%application_role%')]
		private readonly string $applicationRole = 'api',
	) {
	}

	public function isOptional(): bool
	{
		return true;
	}

	/**
	 * @return list<string>
	 */
	public function warmUp(string $cacheDir, ?string $buildDir = null): array
	{
		$this->generator->ensureDirectory();

		if ('consumer' !== $this->applicationRole) {
			try {
				$this->entityManager->getClassMetadata(DbObject::class);
			} catch (\Throwable) {
				// Offline builds or pre-migration environments skip DB lookup cleanly.
			}
		}

		return [];
	}
}
