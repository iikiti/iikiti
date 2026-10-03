<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Usage;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use iikiti\CMS\Web\BlockEditor\Usage\BlockUsageIndexer;
use PHPUnit\Framework\TestCase;

final class BlockUsageIndexerTest extends TestCase
{
	/**
	 * @param array<string,list<array<string,mixed>>> $blocks
	 */
	private function template(array $blocks, int $id): Template
	{
		$template = new Template();
		$properties = (new \ReflectionClass(DbObject::class))->getProperty('properties');
		$properties->setValue($template, new ArrayCollection());
		$idProperty = (new \ReflectionClass(DbObject::class))->getProperty('id');
		$idProperty->setValue($template, $id);
		$template->setProperty('blocks', $blocks);

		return $template;
	}

	public function testCountsAndUsedByAreAggregatedPerType(): void
	{
		$template = $this->template([
			'main' => [
				['type' => 'heading', 'children' => []],
				['type' => 'container', 'children' => [
					['type' => 'text'],
					['type' => 'text'],
				]],
			],
		], 7);

		$templateRepository = $this->createStub(EntityRepository::class);
		$templateRepository->method('findAll')->willReturn([$template]);
		$propertyRepository = $this->createStub(EntityRepository::class);
		$propertyRepository->method('findBy')->willReturn([]);

		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturnCallback(
			static fn (string $class): EntityRepository => Template::class === $class ? $templateRepository : $propertyRepository,
		);

		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider()]);
		$usage = (new BlockUsageIndexer($entityManager, $registry))->usage();

		$byType = [];
		foreach ($usage as $row) {
			$byType[$row['type']] = $row;
		}

		self::assertSame(2, $byType['text']['count']);
		self::assertSame(1, $byType['container']['count']);
		self::assertSame(1, $byType['heading']['count']);
		self::assertSame([['contextType' => 'template', 'contextId' => 7]], $byType['text']['usedBy']);
		self::assertSame($registry->get('text')?->label, $byType['text']['label']);
	}

	public function testDraftTreesAreCountedToo(): void
	{
		$template = $this->template(['main' => [['type' => 'text']]], 3);
		$template->setProperty('blocks_draft', ['main' => [['type' => 'text'], ['type' => 'text']]]);

		$templateRepository = $this->createStub(EntityRepository::class);
		$templateRepository->method('findAll')->willReturn([$template]);
		$propertyRepository = $this->createStub(EntityRepository::class);
		$propertyRepository->method('findBy')->willReturn([]);

		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturnCallback(
			static fn (string $class): EntityRepository => Template::class === $class ? $templateRepository : $propertyRepository,
		);

		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider()]);
		$usage = (new BlockUsageIndexer($entityManager, $registry))->usage();

		$text = null;
		foreach ($usage as $row) {
			if ('text' === $row['type']) {
				$text = $row;
			}
		}

		self::assertNotNull($text);
		self::assertSame(3, $text['count']);
	}
}
