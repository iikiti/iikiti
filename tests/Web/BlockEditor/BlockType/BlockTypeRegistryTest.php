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
		$this->assertContains('form', $types);
		$this->assertContains('button', $types);
		$this->assertContains('input', $types);
		$this->assertContains('textarea', $types);
		$this->assertContains('select', $types);
		$this->assertContains('range', $types);
		$this->assertContains('checkbox', $types);
		$this->assertContains('radio', $types);
		$this->assertContains('legend', $types);
		$this->assertContains('fieldset', $types);
	}

	public function testFormBlockSchemasExposeDefaultsAndNativeChildRules(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]);

		$form = $registry->get('form');
		$button = $registry->get('button');
		$input = $registry->get('input');
		$fieldset = $registry->get('fieldset');
		$select = $registry->get('select');
		$buttonTypeField = array_values(array_filter($button->contentFields, static fn (array $field): bool => 'type' === $field['key']))[0] ?? [];
		$inputTypeField = array_values(array_filter($input->contentFields, static fn (array $field): bool => 'type' === $field['key']))[0] ?? [];
		$selectOptionsField = array_values(array_filter($select->contentFields, static fn (array $field): bool => 'options' === $field['key']))[0] ?? [];
		$formMethodField = array_values(array_filter($form->contentFields, static fn (array $field): bool => 'method' === $field['key']))[0] ?? [];

		self::assertTrue($form->acceptsChildren);
		self::assertSame(['input', 'textarea', 'select', 'range', 'checkbox', 'radio', 'button', 'fieldset'], $form->childTypes());
		self::assertSame(['legend', 'input', 'textarea', 'select', 'range', 'checkbox', 'radio', 'button'], $fieldset->childTypes());
		self::assertSame(['button', 'submit'], array_column($buttonTypeField['options'] ?? [], 'value'));
		self::assertSame('button', $buttonTypeField['default'] ?? null);
		self::assertSame('form', $form->wrapperTag);
		self::assertSame('fieldset', $fieldset->wrapperTag);
		self::assertSame(['text', 'password', 'email', 'number'], array_column($inputTypeField['options'] ?? [], 'value'));
		self::assertSame([['label' => 'Choose an option', 'value' => '']], $selectOptionsField['default'] ?? []);
		self::assertSame('post', $formMethodField['default'] ?? null);
	}

	public function testCoreTypesExposeOrderedStyleGroupMetadata(): void
	{
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]);

		foreach ($registry->all() as $type) {
			$fields = [];
			foreach ($type->styleFields as $field) {
				$fields[$field['key']] = $field;
			}

			self::assertSame('layout', $fields['display']['group'] ?? null);
			self::assertSame(10, $fields['display']['order'] ?? null);
			self::assertSame('size', $fields['width']['group'] ?? null);
			self::assertSame('spacing', $fields['padding']['group'] ?? null);
			self::assertSame('typography', $fields['color']['group'] ?? null);
			self::assertSame('background', $fields['backgroundColor']['group'] ?? null);
			self::assertSame('borders', $fields['borderRadius']['group'] ?? null);
			self::assertSame('effects', $fields['opacity']['group'] ?? null);
			self::assertSame('position', $fields['position']['group'] ?? null);
		}
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

		$this->assertCount(20, $allowed);
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
		$this->assertCount(21, $registry->all());
	}
}
