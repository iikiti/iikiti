<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\BlockType;

use iikiti\CMS\Web\BlockEditor\BlockType\BlockType;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeInterface;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use PHPUnit\Framework\TestCase;

final class BlockTypeRegistryTest extends TestCase
{
	public function testCoreTypesAreRegistered(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider()]);

		$types = array_keys($registry->all());

		$this->assertContains('container', $types);
		$this->assertContains('dynamic', $types);
		$this->assertContains('heading', $types);
		$this->assertContains('text', $types);
		$this->assertContains('image', $types);
		$this->assertContains('video_embed', $types);
		$this->assertContains('social_embed', $types);
		$this->assertContains('query', $types);
	}

	public function testGetReturnsNullForUnknownType(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider()]);

		$this->assertNull($registry->get('nonexistent'));
	}

	public function testContainerAcceptsAnyChildrenAndOthersDoNot(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider()]);

		$container = $registry->get('container');
		$this->assertNotNull($container);
		$this->assertTrue($container->acceptsChildren);
		// Empty array = any child type allowed.
		$this->assertSame([], $container->childTypes());

		$image = $registry->get('image');
		$this->assertNotNull($image);
		$this->assertFalse($image->acceptsChildren);
	}

	public function testGetAllowedChildrenForContainerReturnsAllTypes(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider()]);

		$allowed = $registry->getAllowedChildrenFor('container');

		$this->assertCount(8, $allowed);
	}

	public function testGetAllowedChildrenForNonContainerReturnsEmpty(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider()]);

		$this->assertSame([], $registry->getAllowedChildrenFor('image'));
	}

	public function testPluginProvidersAreAggregated(): void
	{
		$plugin = new class implements BlockTypeInterface {
			public function getBlockTypes(): array
			{
				return [new BlockType(
					type: 'plugin_hero',
					label: 'Hero',
					category: 'layout',
					contentFields: [['key' => 'title', 'label' => 'Title', 'type' => 'text']],
				)];
			}
		};

		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(), $plugin]);

		$this->assertNotNull($registry->get('plugin_hero'));
		$this->assertCount(9, $registry->all());
	}
}
