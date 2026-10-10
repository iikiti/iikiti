<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\BlockType;

use iikiti\CMS\Tests\Support\IconResolverFactory;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockType;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeInterface;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use PHPUnit\Framework\TestCase;

final class BlockTypeRegistryTest extends TestCase
{
	public function testCoreTypesAreRegistered(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]);

		$types = array_keys($registry->all());

		$this->assertContains('container', $types);
		$this->assertContains('dynamic', $types);
		$this->assertContains('heading', $types);
		$this->assertContains('text', $types);
		$this->assertContains('image', $types);
		$this->assertContains('video_embed', $types);
		$this->assertContains('social_embed', $types);
		$this->assertContains('query', $types);
		$this->assertContains('icon', $types);
	}

	public function testGetReturnsNullForUnknownType(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]);

		$this->assertNull($registry->get('nonexistent'));
	}

	public function testContainerAcceptsAnyChildrenAndOthersDoNot(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]);

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
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]);

		$allowed = $registry->getAllowedChildrenFor('container');

		// All 10 core types, including `inline_text` and `icon` (any container accepts all).
		$this->assertCount(10, $allowed);
	}

	public function testGetAllowedChildrenForNonContainerReturnsEmpty(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]);

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

		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly()), $plugin]);

		$this->assertNotNull($registry->get('plugin_hero'));
		// 10 core types + 1 plugin type.
		$this->assertCount(11, $registry->all());
	}
}
