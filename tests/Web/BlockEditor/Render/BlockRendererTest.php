<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Tests\Support\IconResolverFactory;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use iikiti\CMS\Web\BlockEditor\Query\QueryDefinition;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\BlockEditor\Query\QueryFieldCatalog;
use iikiti\CMS\Web\BlockEditor\Query\QuerySourceInterface;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BlockRendererTest extends TestCase
{
	private BlockRenderer $renderer;

	protected function setUp(): void
	{
		$this->renderer = $this->makeRenderer();
	}

	private function makeRenderer(?QuerySourceInterface $source = null): BlockRenderer
	{
		$twig = new Environment(new FilesystemLoader(__DIR__.'/../../../../templates'), [
			'strict_variables' => false,
		]);
		// The app registers BlockTwigExtension globally; the bare test environment
		// stubs `iikiti_query` so query.twig compiles. The stub throws to prove the
		// renderer supplies `items` itself (no double query execution).
		$twig->addFunction(new \Twig\TwigFunction('iikiti_query', static function (): never {
			throw new \RuntimeException('iikiti_query must not be called when the renderer supplies items');
		}, ['is_safe' => ['html']]));

		return new BlockRenderer(
			new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]),
			$twig,
			new QueryExecutor(null !== $source ? [$source] : []),
			new QueryFieldCatalog($this->createStub(EntityManagerInterface::class)),
		);
	}

	/**
	 * A query source serving in-memory DbObject rows (keyed by `objects` source).
	 *
	 * @param list<DbObject> $items
	 */
	private function makeSource(array $items): QuerySourceInterface
	{
		return new class($items) implements QuerySourceInterface {
			/** @param list<DbObject> $items */
			public function __construct(
				private readonly array $items,
			) {
			}

			#[\Override]
			public function getName(): string
			{
				return 'objects';
			}

			#[\Override]
			public function execute(QueryDefinition $definition, ?int $siteId): array
			{
				return $this->items;
			}
		};
	}

	/**
	 * A DbObject with its property collection initialised and one property set.
	 */
	private function makeObject(string $title): DbObject
	{
		$object = new DbObject();
		$properties = (new \ReflectionClass(DbObject::class))->getProperty('properties');
		$properties->setValue($object, new ArrayCollection());
		$object->setProperty('title', $title);

		return $object;
	}

	public function testPublicRegionRenderSkipsRootLevelNonContainers(): void
	{
		$tree = [
			['type' => 'text', 'content' => ['content' => '<p>Root leak</p>'], 'style' => ['base' => []]],
			['type' => 'container', 'content' => [], 'style' => ['base' => []], 'children' => [
				['type' => 'text', 'content' => ['content' => '<p>Nested ok</p>'], 'style' => ['base' => []]],
			]],
		];

		$html = $this->renderer->renderRegionTree($tree, new BlockRenderContext(editorMode: false));

		self::assertStringNotContainsString('Root leak', $html);
		self::assertStringContainsString('Nested ok', $html);
	}

	public function testRenderTreeEmitsNoMetadataInThePublicMode(): void
	{
		$context = new BlockRenderContext(editorMode: false);
		$html = $this->renderer->renderRegionTree($this->sampleTree(), $context);

		$this->assertStringContainsString('iikiti-block iikiti-block--text', $html);
		$this->assertStringNotContainsString('data-block-id', $html);
		self::assertStringNotContainsString('data-block-type', $html);
	}

	public function testRenderTreeEmitsMetadataInTheEditMode(): void
	{
		$context = new BlockRenderContext(editorMode: true);
		$html = $this->renderer->renderRegionTree($this->sampleTree(), $context);

		$this->assertStringContainsString('data-block-type="text"', $html);
		$this->assertStringContainsString('data-block-content=', $html);
		$this->assertStringContainsString('data-block-style=', $html);
		$this->assertStringContainsString('iikiti-text', $html);
	}

	public function testContainerRecursivelyRendersChildren(): void
	{
		$tree = [
			[
				'id' => 'c1',
				'type' => 'container',
				'content' => [],
				'style' => ['base' => ['layout' => 'flex']],
				'children' => [['type' => 'heading', 'content' => ['level' => '2', 'text' => 'Hi']]],
			],
		];

		$html = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		$this->assertStringContainsString('iikiti-container', $html);
		$this->assertStringContainsString('iikiti-heading', $html);
		$this->assertStringContainsString('<h2', $html);
	}

	public function testUnknownBlockTypeRendersPlaceholder(): void
	{
		$html = $this->renderer->renderTree([['type' => 'not_a_block']], new BlockRenderContext(editorMode: false));

		$this->assertStringContainsString('iikiti-block--placeholder', $html);
		$this->assertStringNotContainsString('data-block-type', $html);
	}

	public function testEditHintBlockIsHiddenFromPublicButShownToEditors(): void
	{
		$tree = [
			[
				'type' => 'text',
				'content' => ['content' => '<p>Edit this page with <code>?edit</code>.</p>'],
				'style' => ['base' => []],
			],
			[
				'type' => 'text',
				'content' => ['content' => '<p>Public content</p>'],
				'style' => ['base' => []],
			],
		];

		$public = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false, canEdit: false));
		$editor = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: true, canEdit: true));

		// Public output: instructional hint is suppressed for non-editors,
		// real content survives.
		self::assertStringNotContainsString('Edit this page with', $public);
		self::assertStringContainsString('Public content', $public);
		self::assertStringNotContainsString('data-block-id', $public);
		self::assertStringNotContainsString('data-block-type', $public);

		// Editor output (logged-in editor): hint is visible and hydratable.
		self::assertStringContainsString('Edit this page with', $editor);
		self::assertStringContainsString('data-block-type="text"', $editor);
	}

	public function testCanEditWithoutEditorModeShowsContentButNoMetadata(): void
	{
		// Pre-editor mode: user is logged in and can edit, but ?edit is not in
		// the URL. Blocks should be visible (edit hint shown) but data-block-*
		// metadata must be absent — it is only emitted in editorMode.
		$tree = [
			[
				'type' => 'text',
				'content' => ['content' => '<p>Edit this page with <code>?edit</code>.</p>'],
				'style' => ['base' => []],
			],
			[
				'type' => 'text',
				'content' => ['content' => '<p>Public content</p>'],
				'style' => ['base' => []],
			],
		];

		$html = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false, canEdit: true));

		// Edit hint is visible to editors even without ?edit.
		self::assertStringContainsString('Edit this page with', $html);
		self::assertStringContainsString('Public content', $html);
		// No block metadata — only emitted in editorMode.
		self::assertStringNotContainsString('data-block-id', $html);
		self::assertStringNotContainsString('data-block-type', $html);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	/**
	 * Root-level non-containers are not rendered publicly (RootContainerRule), so
	 * fixtures place their blocks inside a container, matching real trees.
	 *
	 * @param list<array<string,mixed>> $nodes
	 *
	 * @return list<array<string,mixed>>
	 */
	private function inContainer(array $nodes): array
	{
		return [['id' => 'root-container', 'type' => 'container', 'content' => [], 'style' => ['base' => []], 'children' => $nodes]];
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function sampleTree(): array
	{
		return $this->inContainer([
			[
				'id' => 'b1',
				'type' => 'text',
				'content' => ['content' => '<p>Hello</p>'],
				'style' => ['base' => ['color' => '#000']],
			],
		]);
	}

	public function testEachBlockGetsAPerBlockClassFromItsId(): void
	{
		$tree = [[
			'id' => 'hero-title',
			'type' => 'container',
			'children' => [],
		]];

		$html = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		self::assertStringContainsString('iikiti-block-id--hero-title', $html);
	}

	public function testUnsafeIdsGetAHashSuffixSoDistinctIdsStayDistinct(): void
	{
		$spaced = BlockRenderer::blockIdClass('my block');
		$dotted = BlockRenderer::blockIdClass('my.block');

		self::assertMatchesRegularExpression('/^iikiti-block-id--my-block-[0-9a-f]{8}$/', $spaced);
		self::assertMatchesRegularExpression('/^iikiti-block-id--my-block-[0-9a-f]{8}$/', $dotted);
		self::assertNotSame($spaced, $dotted);
	}

	public function testSafeIdsAreUsedWithoutAHashSuffix(): void
	{
		self::assertSame('iikiti-block-id--blk_abc123', BlockRenderer::blockIdClass('blk_abc123'));
	}

	public function testElementIdAndCssClassRenderOnTheWrapper(): void
	{
		$tree = [[
			'type' => 'text',
			'content' => ['content' => '<p>Hi</p>'],
			'element' => ['id' => 'hero', 'cssClass' => 'lead muted'],
		]];

		$html = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		self::assertStringContainsString('class="iikiti-block iikiti-block--text lead muted"', $html);
		self::assertStringContainsString('id="hero"', $html);
	}

	public function testElementAttributesUseAnAllowlist(): void
	{
		$tree = [[
			'type' => 'text',
			'content' => ['content' => '<p>Hi</p>'],
			'element' => ['attributes' => [
				['name' => 'data-foo', 'value' => 'bar'],
				['name' => 'title', 'value' => 'Hello "world"'],
				['name' => 'onclick', 'value' => 'alert(1)'],
				['name' => 'style', 'value' => 'color:red'],
				['name' => 'href', 'value' => 'javascript:alert(1)'],
				['name' => '2bad', 'value' => 'x'],
			]],
		]];

		$html = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		self::assertStringContainsString('data-foo="bar"', $html);
		self::assertStringContainsString('title="Hello &quot;world&quot;"', $html);
		self::assertStringNotContainsString('onclick', $html);
		self::assertStringNotContainsString('style="color:red"', $html);
		self::assertStringNotContainsString('javascript:', $html);
		self::assertStringNotContainsString('2bad', $html);
	}

	public function testElementMetadataIsEmittedInEditMode(): void
	{
		$tree = [[
			'type' => 'text',
			'content' => ['content' => '<p>Hi</p>'],
			'element' => ['id' => 'x'],
		]];

		$html = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: true));

		self::assertStringContainsString('data-block-element=', $html);
	}

	public function testHeadingRendersInlineTextChildrenInsideTheHeadingElement(): void
	{
		$tree = [
			[
				'type' => 'heading',
				'content' => ['level' => '2', 'text' => 'Hi '],
				'style' => ['base' => []],
				'children' => [
					['type' => 'inline_text', 'content' => ['text' => 'world', 'tag' => 'strong'], 'style' => ['base' => []]],
					['type' => 'inline_text', 'content' => ['text' => '!'], 'style' => ['base' => []]],
				],
			],
		];

		$html = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		// Inline children use the block type's `span` wrapper so the markup
		// stays valid inside an <h2> (the block wrapper itself remains a div).
		self::assertStringContainsString(
			'<h2 class="iikiti-heading" data-block-children>Hi <span class="iikiti-block iikiti-block--inline_text"><strong>world</strong></span><span class="iikiti-block iikiti-block--inline_text">!</span></h2>',
			$html
		);
	}

	public function testInlineTextTagIsAllowlisted(): void
	{
		$tree = [
			['type' => 'inline_text', 'content' => ['text' => '<img src=x onerror=alert(1)>', 'tag' => 'script'], 'style' => ['base' => []]],
			['type' => 'inline_text', 'content' => ['text' => 'ok', 'tag' => 'em'], 'style' => ['base' => []]],
		];

		$html = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		// A disallowed `tag` falls back to a bare escaped text node.
		self::assertStringNotContainsString('<script', $html);
		self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
		self::assertStringContainsString('<em>ok</em>', $html);
	}

	public function testQueryChildrenRenderOncePerResultWithBindingsResolved(): void
	{
		$renderer = $this->makeRenderer($this->makeSource([
			$this->makeObject('First <b>row</b>'),
			$this->makeObject('Second'),
		]));

		$tree = [
			[
				'type' => 'query',
				'content' => ['source' => 'objects', 'objectType' => '', 'limit' => 10],
				'style' => ['base' => []],
				'children' => [
					[
						'type' => 'heading',
						'content' => ['level' => '3', 'text' => 'Fallback'],
						'style' => ['base' => []],
						// The heading text is bound to each row's `title`.
						'bindings' => ['text' => 'title'],
					],
				],
			],
		];

		$html = $renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		// One child render per result row, with the binding resolved per row.
		self::assertSame(2, substr_count($html, 'iikiti-query__item'));
		self::assertSame(2, substr_count($html, '<h3 class="iikiti-heading" data-block-children>'));
		// Resolved values are reduced to plain text (markup stripped) before the
		// template escapes them once — raw markup never reaches the output.
		self::assertStringContainsString('<h3 class="iikiti-heading" data-block-children>First row</h3>', $html);
		self::assertStringContainsString('Second', $html);
		// The static fallback never leaks when the binding resolves.
		self::assertStringNotContainsString('Fallback', $html);
		self::assertStringNotContainsString('<b>', $html);
	}

	public function testQueryBindingFallsBackToStaticContentWhenUnresolved(): void
	{
		$renderer = $this->makeRenderer($this->makeSource([
			$this->makeObject('Row one'), // has no `slug` property
		]));

		$tree = [
			[
				'type' => 'query',
				'content' => ['source' => 'objects', 'objectType' => '', 'limit' => 10],
				'style' => ['base' => []],
				'children' => [
					[
						'type' => 'heading',
						'content' => ['level' => '3', 'text' => 'Static fallback'],
						'style' => ['base' => []],
						'bindings' => ['text' => 'slug'],
					],
				],
			],
		];

		$html = $renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		// Unresolvable binding (null) keeps the field's static content.
		self::assertStringContainsString('Static fallback', $html);
	}

	public function testBindingsMetadataIsEmittedInEditMode(): void
	{
		$tree = [[
			'type' => 'text',
			'content' => ['content' => '<p>Hi</p>'],
			'bindings' => ['content' => 'title'],
		]];

		$html = $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: true));

		self::assertStringContainsString('data-block-bindings=', $html);
		self::assertStringNotContainsString('data-block-bindings', $this->renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false)));
	}

	public function testSensitiveAndNonTextBindingsNeverOverlay(): void
	{
		$object = $this->makeObject('Visible title');
		$object->setProperty('password', 'hash-secret');
		$renderer = $this->makeRenderer($this->makeSource([$object]));

		$tree = [[
			'type' => 'query',
			'content' => ['source' => 'objects', 'objectType' => '', 'limit' => 10],
			'style' => ['base' => []],
			'children' => [
				// Sensitive accessor: must fall back to the static text.
				['type' => 'heading', 'content' => ['level' => '3', 'text' => 'Static A'], 'style' => ['base' => []], 'bindings' => ['text' => 'password']],
				// Select field (level) is not a bindable target: the tampered row value must not reach the tag name.
				['type' => 'heading', 'content' => ['level' => '3', 'text' => 'Static B'], 'style' => ['base' => []], 'bindings' => ['level' => 'title']],
			],
		]];

		$html = $renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		self::assertStringNotContainsString('hash-secret', $html);
		self::assertStringContainsString('Static A', $html);
		self::assertStringNotContainsString('Visible title', $html);
		self::assertStringContainsString('<h3 class="iikiti-heading"', $html);
	}

	public function testInvalidHeadingLevelFallsBackToH2(): void
	{
		$html = $this->renderer->renderTree([[
			'type' => 'heading',
			'content' => ['level' => '2" onmouseover="x', 'text' => 'Hi'],
			'style' => ['base' => []],
		]], new BlockRenderContext(editorMode: false));

		self::assertStringNotContainsString('onmouseover', $html);
		self::assertStringContainsString('<h2 class="iikiti-heading"', $html);
	}

	public function testEditorKeepsQueryChildTemplateWhenNoRows(): void
	{
		$renderer = $this->makeRenderer($this->makeSource([]));
		$tree = [[
			'type' => 'query',
			'content' => ['source' => 'objects', 'objectType' => '', 'limit' => 10],
			'style' => ['base' => []],
			'children' => [['type' => 'heading', 'content' => ['level' => '3', 'text' => 'Template'], 'style' => ['base' => []]]],
		]];

		$editorHtml = $renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: true));
		$publicHtml = $renderer->renderRegionTree($this->inContainer($tree), new BlockRenderContext(editorMode: false));

		self::assertStringContainsString('data-block-item-children', $editorHtml);
		self::assertStringContainsString('Template', $editorHtml);
		self::assertStringContainsString('No results found.', $publicHtml);
		self::assertStringNotContainsString('Template', $publicHtml);
	}

	public function testFormBlocksRenderNativeSemanticElementsAndEditorMetadata(): void
	{
		$tree = $this->inContainer([[
			'id' => 'form-1',
			'type' => 'form',
			'content' => ['action' => '/send', 'method' => 'POST'],
			'children' => [[
				'id' => 'fieldset-1',
				'type' => 'fieldset',
				'children' => [
					['id' => 'legend-1', 'type' => 'legend', 'content' => ['text' => 'Profile']],
					['type' => 'input', 'content' => ['label' => 'Name', 'name' => 'name', 'type' => 'text']],
					['type' => 'textarea', 'content' => ['label' => 'Biography', 'name' => 'bio', 'rows' => 4]],
					['type' => 'select', 'content' => ['label' => 'Country', 'name' => 'country', 'options' => [['label' => 'Canada', 'value' => 'ca']]]],
					['type' => 'range', 'content' => ['label' => 'Rating', 'name' => 'rating', 'min' => 0, 'max' => 10, 'step' => 1, 'value' => 5]],
					['type' => 'checkbox', 'content' => ['label' => 'Subscribe', 'name' => 'subscribe', 'value' => 'yes', 'checked' => true]],
					['type' => 'radio', 'content' => ['label' => 'Option A', 'name' => 'choice', 'value' => 'a']],
					['type' => 'button', 'content' => ['text' => 'Action']],
					['type' => 'button', 'content' => ['text' => 'Send', 'type' => 'submit']],
				],
			]],
		]]);

		$publicHtml = $this->renderer->renderRegionTree($tree, new BlockRenderContext(editorMode: false));
		$editorHtml = $this->renderer->renderRegionTree($tree, new BlockRenderContext(editorMode: true));

		self::assertStringContainsString('<form class="iikiti-block iikiti-block--form iikiti-block-id--form-1" action="/send" method="post" data-block-children>', $publicHtml);
		self::assertStringContainsString('<fieldset class="iikiti-block iikiti-block--fieldset iikiti-block-id--fieldset-1" data-block-children><legend class="iikiti-block iikiti-block--legend iikiti-block-id--legend-1">Profile
</legend>', $publicHtml);
		self::assertStringContainsString('<input type="text" name="name">', $publicHtml);
		self::assertStringContainsString('<textarea rows="4" name="bio"></textarea>', $publicHtml);
		self::assertStringContainsString('<option value="ca">Canada</option>', $publicHtml);
		self::assertStringContainsString('<input type="range" name="rating" min="0" max="10" step="1" value="5">', $publicHtml);
		self::assertStringContainsString('<input type="checkbox" name="subscribe" value="yes" checked>', $publicHtml);
		self::assertStringContainsString('<input type="radio" name="choice" value="a">', $publicHtml);
		self::assertStringContainsString('<button type="button">Action</button>', $publicHtml);
		self::assertStringContainsString('<button type="submit">Send</button>', $publicHtml);
		self::assertStringNotContainsString('data-block-id', $publicHtml);
		self::assertStringContainsString('data-block-id="form-1"', $editorHtml);
		self::assertStringContainsString('data-block-children', $editorHtml);
	}

	public function testButtonDefaultsToButtonAndCanSubmitInsideAnyContainer(): void
	{
		$tree = $this->inContainer([
			['type' => 'button', 'content' => ['text' => 'Open']],
			['type' => 'button', 'content' => ['text' => 'Send', 'type' => 'submit']],
		]);

		$html = $this->renderer->renderRegionTree($tree, new BlockRenderContext(editorMode: false));

		self::assertStringContainsString('<button type="button">Open</button>', $html);
		self::assertStringContainsString('<button type="submit">Send</button>', $html);
	}

	public function testFormActionAndInputMarkupRejectUnsafeValuesAndEscapeText(): void
	{
		$tree = $this->inContainer([[
			'type' => 'form',
			'content' => ['action' => 'javascript:alert(1)', 'method' => 'delete'],
			'element' => ['attributes' => [['name' => 'action', 'value' => 'javascript:alert(2)'], ['name' => 'method', 'value' => 'delete']]],
			'children' => [[
				'type' => 'input',
				'content' => ['type' => 'submit" onfocus="alert(1)', 'label' => '<img src=x onerror=alert(1)>', 'name' => 'field', 'value' => '<script>bad</script>'],
			], [
				'type' => 'select',
				'content' => ['options' => [['label' => '<script>bad</script>', 'value' => 'x']], 'value' => 'x'],
			]],
		]]);

		$html = $this->renderer->renderRegionTree($tree, new BlockRenderContext(editorMode: false));

		self::assertStringContainsString('<form class="iikiti-block iikiti-block--form" method="post" data-block-children>', $html);
		self::assertStringNotContainsString('javascript:', $html);
		self::assertStringContainsString('<input type="text" name="field" value="&lt;script&gt;bad&lt;/script&gt;">', $html);
		self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
		self::assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;</option>', $html);
		self::assertStringNotContainsString('<script>bad</script>', $html);
	}
}
