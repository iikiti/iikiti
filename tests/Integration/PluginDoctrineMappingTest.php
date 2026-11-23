<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\Extension\ConsentManagement\Entity\ConsentRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies PluginBundle::prependExtension() auto-registers plugin Doctrine
 * mappings and registers ConsentRecord into DbObject's STI discriminator map.
 */
final class PluginDoctrineMappingTest extends KernelTestCase
{
	public function testConsentRecordIsMappedInDoctrineAndDiscriminatorMap(): void
	{
		self::bootKernel();
		/** @var EntityManagerInterface $em */
		$em = self::getContainer()->get(EntityManagerInterface::class);

		$allClasses = array_map(
			static fn ($meta): string => $meta->getName(),
			$em->getMetadataFactory()->getAllMetadata(),
		);

		self::assertContains(ConsentRecord::class, $allClasses);

		$rootMeta = $em->getClassMetadata(DbObject::class);
		self::assertArrayHasKey(ConsentRecord::TYPE, $rootMeta->discriminatorMap);
		self::assertSame(ConsentRecord::class, $rootMeta->discriminatorMap[ConsentRecord::TYPE]);
	}
}
