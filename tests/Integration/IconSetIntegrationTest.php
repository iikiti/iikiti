<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Web\Icon\IconResolver;
use iikiti\CMS\Web\Icon\IconSetManager;
use iikiti\CMS\Web\Icon\SvgRejectedException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end icon set path against the real database: a managed set, a sanitised
 * upload, and resolution from a block reference. Every test runs in a transaction
 * that is rolled back, so the database is left unchanged.
 */
final class IconSetIntegrationTest extends KernelTestCase
{
	private EntityManagerInterface $em;
	private IconSetManager $manager;
	private IconResolver $resolver;

	protected function setUp(): void
	{
		self::bootKernel();
		$container = self::getContainer();
		$this->em = $container->get(EntityManagerInterface::class);
		$this->manager = $container->get(IconSetManager::class);
		$this->resolver = $container->get(IconResolver::class);
		$this->em->getConnection()->beginTransaction();
	}

	protected function tearDown(): void
	{
		if ($this->em->getConnection()->isTransactionActive()) {
			$this->em->getConnection()->rollBack();
		}
		parent::tearDown();
	}

	private const CLEAN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 1h22v22H1z"/></svg>';

	public function testStoredIconResolvesFromSetSlashName(): void
	{
		$slug = 'test-'.bin2hex(random_bytes(4));
		$this->manager->createSet($slug, 'Test set');
		$this->manager->putIcon($this->manager->findSet($slug), 'logo', self::CLEAN_SVG);

		$resolved = $this->resolver->resolve($slug.'/logo');

		self::assertNotNull($resolved);
		self::assertSame('logo', $resolved['name']);
		self::assertStringContainsString('<path', (string) $resolved['set']->svg('logo'));
	}

	public function testHostileSvgIsRejectedAndNotStored(): void
	{
		$slug = 'test-'.bin2hex(random_bytes(4));
		$set = $this->manager->createSet($slug, 'Test set');

		$this->expectException(SvgRejectedException::class);
		try {
			$this->manager->putIcon($set, 'evil', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
		} finally {
			self::assertNull($this->resolver->resolve($slug.'/evil'));
		}
	}

	public function testUnknownSetResolvesToNull(): void
	{
		self::assertNull($this->resolver->resolve('no-such-set/icon'));
	}

	public function testBareNameStillResolvesToBundledLucide(): void
	{
		$resolved = $this->resolver->resolve('box');

		self::assertNotNull($resolved);
		self::assertSame('lucide', $resolved['set']->name());
	}

	public function testDuplicateSlugIsRejected(): void
	{
		$slug = 'test-'.bin2hex(random_bytes(4));
		$this->manager->createSet($slug, 'First');

		$this->expectException(\InvalidArgumentException::class);
		$this->manager->createSet($slug, 'Second');
	}

	public function testReplacingAnIconKeepsOneRowPerName(): void
	{
		$slug = 'test-'.bin2hex(random_bytes(4));
		$set = $this->manager->createSet($slug, 'Test set');
		$this->manager->putIcon($set, 'logo', self::CLEAN_SVG);
		$this->manager->putIcon($set, 'logo', '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="1" cy="1" r="1"/></svg>');

		$icons = $this->manager->iconsIn($set);

		self::assertCount(1, $icons);
		self::assertStringContainsString('<circle', $icons[0]->getSvg());
	}
}
