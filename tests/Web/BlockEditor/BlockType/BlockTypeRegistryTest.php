<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\BlockType;

use iikiti\CMS\Tests\Support\IconResolverFactory;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockType;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeInterface;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use iikiti\Extension\Embeds\BlockType\EmbedBlockTypeProvider;
use iikiti\Extension\Forms\BlockType\FormBlockTypeProvider;
use PHPUnit\Framework\TestCase;

final class BlockTypeRegistryTest extends TestCase
{
	private function registry(BlockTypeInterface ...$extra): BlockTypeRegistry
	{
		return new BlockTypeRegistry([
			new CoreBlockTypeProvider(IconResolverFactory::bundledOnly()),
			new EmbedBlockTypeProvider(),
			new FormBlockTypeProvider(),
			...$extra,
		]);
	}

	public function testCoreTypesAreRegistered(): void
	{
		$registry = $this->registry();

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
		$registry = $this->registry();

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
		$registry = $this->registry();

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
		$registry = $this->registry();

		$this->assertNull($registry->get('nonexistent'));
	}

	public function testContainerAcceptsAnyChildrenAndOthersDoNot(): void
	{
		$registry = $this->registry();

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
		$registry = $this->registry();

		$allowed = $registry->getAllowedChildrenFor('container');

		$this->assertCount(20, $allowed);
	}

	public function testGetAllowedChildrenForNonContainerReturnsEmpty(): void
	{
		$registry = $this->registry();

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

		$registry = $this->registry($plugin);

		$this->assertNotNull($registry->get('plugin_hero'));
		$this->assertSame('core', $registry->get('icon')?->source);
		$this->assertSame('iikiti-embeds', $registry->get('video_embed')?->source);
		$this->assertSame('iikiti-embeds', $registry->get('social_embed')?->source);
		$this->assertSame('iikiti-forms', $registry->get('form')?->source);
		$this->assertCount(21, $registry->all());
	}

	public function testProvidersLoadLazilyAndClearOnReset(): void
	{
		$calls = 0;
		$provider = new class($calls) implements BlockTypeInterface {
			public function __construct(private int &$calls)
			{
			}

			public function getBlockTypes(): array
			{
				++$this->calls;

				return [new BlockType(
					type: 'lazy_block',
					label: 'Lazy '.$this->calls,
					category: 'content',
				)];
			}
		};

		$registry = new BlockTypeRegistry([$provider]);
		self::assertSame(0, $calls);

		$first = $registry->get('lazy_block');
		self::assertSame('Lazy 1', $first?->label);
		self::assertSame(1, $calls);
		$cached = $registry->get('lazy_block');
		self::assertSame('Lazy 1', $cached?->label);
		self::assertSame(1, $calls);

		$registry->reset();
		$reloaded = $registry->get('lazy_block');
		self::assertSame('Lazy 2', $reloaded?->label);
		self::assertSame(2, $calls);
	}
}
