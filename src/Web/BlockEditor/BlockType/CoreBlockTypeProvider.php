<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\BlockType;

/**
 * Provides iikiti's core block types. Plugins provide additional (or replacement)
 * types by implementing {@see BlockTypeInterface}; tagging happens automatically
 * via the attribute on the interface.
 */
final class CoreBlockTypeProvider implements BlockTypeInterface
{
	/**
	 * Wrapper elements an `inline_text` child may render as. `plain` renders a
	 * bare (escaped) text node. The allowlist is enforced both here and in
	 * `templates/blocks/inline_text.twig` so a stored `tag` can never inject markup.
	 */
	public const INLINE_TAGS = ['span', 'em', 'strong', 'b', 'u', 'i', 'small', 'code', 'mark'];

	#[\Override]
	public function getBlockTypes(): array
	{
		return [
			$this->container(),
			$this->dynamic(),
			$this->heading(),
			$this->inlineText(),
			$this->text(),
			$this->image(),
			$this->videoEmbed(),
			$this->socialEmbed(),
			$this->query(),
		];
	}

	/**
	 * Shared baseline element-level fields for every core block type.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function elementFields(): array
	{
		return [
			['key' => 'id', 'label' => 'ID', 'type' => 'text', 'placeholder' => 'element-id'],
			['key' => 'cssClass', 'label' => 'CSS class', 'type' => 'text', 'placeholder' => 'space-separated classes'],
		];
	}

	private function container(): BlockType
	{
		return new BlockType(
			type: 'container',
			label: 'Container',
			category: 'layout',
			acceptsChildren: true,
			allowedChildTypes: [], // empty = any block type allowed
			contentFields: [],
			styleFields: [
				['key' => 'layout', 'label' => 'Layout', 'type' => 'select', 'options' => [
					['value' => 'block', 'label' => 'Block'],
					['value' => 'flex', 'label' => 'Flex'],
					['value' => 'grid', 'label' => 'Grid'],
				], 'default' => 'block'],
				['key' => 'gap', 'label' => 'Gap', 'type' => 'spacing'],
				['key' => 'padding', 'label' => 'Padding', 'type' => 'spacing'],
				['key' => 'align', 'label' => 'Alignment', 'type' => 'align',
					'options' => [['value' => 'start', 'label' => 'Start'], ['value' => 'center', 'label' => 'Center'], ['value' => 'end', 'label' => 'End']],
					'default' => 'start'],
			],
			renderTemplate: 'blocks/container.twig',
			editorComponent: 'ContainerBlock',
			elementFields: $this->elementFields(),
			defaults: ['content' => [], 'style' => ['base' => ['layout' => 'block']]],
		);
	}

	private function dynamic(): BlockType
	{
		return new BlockType(
			type: 'dynamic',
			label: 'Dynamic Content',
			category: 'layout',
			acceptsChildren: false,
			renderTemplate: 'blocks/dynamic.twig',
			editorComponent: 'DynamicBlock',
			elementFields: $this->elementFields(),
			defaults: ['content' => ['label' => 'Dynamic content region']],
		);
	}

	private function heading(): BlockType
	{
		return new BlockType(
			type: 'heading',
			label: 'Header',
			category: 'text',
			// A heading is a mini-container for richly emphasised text runs:
			// its only children are `inline_text` blocks (plain text nodes or
			// inline elements — no wysiwyg editing).
			acceptsChildren: true,
			allowedChildTypes: ['inline_text'],
			contentFields: [
				['key' => 'text', 'label' => 'Text', 'type' => 'text'],
				['key' => 'level', 'label' => 'Level', 'type' => 'select',
					'options' => [['value' => '2', 'label' => 'H2'], ['value' => '3', 'label' => 'H3'],
						['value' => '4', 'label' => 'H4'], ['value' => '5', 'label' => 'H5'],
						['value' => '6', 'label' => 'H6']],
					'default' => '2'],
			],
			styleFields: [
				['key' => 'color', 'label' => 'Color', 'type' => 'color'],
				['key' => 'align', 'label' => 'Alignment', 'type' => 'align'],
			],
			renderTemplate: 'blocks/heading.twig',
			editorComponent: 'HeadingBlock',
			elementFields: $this->elementFields(),
			defaults: ['content' => ['level' => '2', 'text' => ''], 'style' => ['base' => []]],
		);
	}

	/**
	 * Select options for the wrapper tag, derived from INLINE_TAGS so the editor
	 * offers exactly the tags the renderer allows.
	 *
	 * @return list<array{value:string,label:string}>
	 */
	private function inlineTagOptions(): array
	{
		$labels = ['em' => 'em (italic)', 'strong' => 'strong', 'b' => 'b (bold)', 'u' => 'u (underline)', 'i' => 'i (italic)', 'mark' => 'mark (highlight)'];
		$options = [['value' => 'plain', 'label' => 'Plain text']];
		foreach (self::INLINE_TAGS as $tag) {
			$options[] = ['value' => $tag, 'label' => $labels[$tag] ?? $tag];
		}

		return $options;
	}

	private function inlineText(): BlockType
	{
		return new BlockType(
			type: 'inline_text',
			label: 'Inline Text',
			// `inline` category types are only offered where a parent explicitly
			// allows them (i.e. inside a `heading` block).
			category: 'inline',
			acceptsChildren: false,
			contentFields: [
				['key' => 'text', 'label' => 'Text', 'type' => 'text', 'required' => true],
				['key' => 'tag', 'label' => 'Wrapper element', 'type' => 'select',
					'options' => $this->inlineTagOptions(),
					'default' => 'plain'],
			],
			styleFields: [
				['key' => 'color', 'label' => 'Color', 'type' => 'color'],
			],
			renderTemplate: 'blocks/inline_text.twig',
			editorComponent: 'InlineTextBlock',
			// Inline content must not render as a `div` inside e.g. `<h2>`.
			wrapperTag: 'span',
			elementFields: $this->elementFields(),
			defaults: ['content' => ['text' => '', 'tag' => 'plain'], 'style' => ['base' => []]],
		);
	}

	private function text(): BlockType
	{
		return new BlockType(
			type: 'text',
			label: 'Text',
			category: 'text',
			acceptsChildren: false,
			contentFields: [
				['key' => 'content', 'label' => 'Content', 'type' => 'richtext', 'required' => true],
			],
			styleFields: [
				['key' => 'color', 'label' => 'Color', 'type' => 'color'],
				['key' => 'align', 'label' => 'Alignment', 'type' => 'align'],
			],
			renderTemplate: 'blocks/text.twig',
			editorComponent: 'TextBlock',
			elementFields: $this->elementFields(),
			defaults: ['content' => ['content' => ''], 'style' => ['base' => []]],
		);
	}

	private function image(): BlockType
	{
		return new BlockType(
			type: 'image',
			label: 'Image',
			category: 'media',
			acceptsChildren: false,
			contentFields: [
				['key' => 'source', 'label' => 'Source', 'type' => 'media', 'required' => true],
				['key' => 'alt', 'label' => 'Alt text', 'type' => 'text', 'required' => true],
				['key' => 'caption', 'label' => 'Caption', 'type' => 'text'],
				['key' => 'link', 'label' => 'Link URL', 'type' => 'url'],
			],
			styleFields: [
				['key' => 'width', 'label' => 'Width', 'type' => 'number', 'prefix' => 'px'],
				['key' => 'height', 'label' => 'Height', 'type' => 'number', 'prefix' => 'px'],
			],
			renderTemplate: 'blocks/image.twig',
			editorComponent: 'ImageBlock',
			elementFields: $this->elementFields(),
			defaults: ['content' => ['source' => ['source' => 'url', 'url' => '']], 'style' => ['base' => []]],
		);
	}

	private function videoEmbed(): BlockType
	{
		return new BlockType(
			type: 'video_embed',
			label: 'Video Embed',
			category: 'media',
			acceptsChildren: false,
			contentFields: [
				['key' => 'url', 'label' => 'Video URL', 'type' => 'url', 'required' => true,
					'placeholder' => 'https://www.youtube.com/watch?v=…'],
			],
			styleFields: [
				['key' => 'aspectRatio', 'label' => 'Aspect ratio', 'type' => 'select',
					'options' => [['value' => '16:9', 'label' => '16:9'], ['value' => '4:3', 'label' => '4:3'],
						['value' => '1:1', 'label' => '1:1']], 'default' => '16:9'],
			],
			renderTemplate: 'blocks/video_embed.twig',
			editorComponent: 'VideoEmbedBlock',
			elementFields: $this->elementFields(),
			defaults: ['content' => ['url' => ''], 'style' => ['base' => ['aspectRatio' => '16:9']]],
		);
	}

	private function socialEmbed(): BlockType
	{
		return new BlockType(
			type: 'social_embed',
			label: 'Social Embed',
			category: 'media',
			acceptsChildren: false,
			contentFields: [
				['key' => 'url', 'label' => 'Post URL', 'type' => 'url', 'required' => true,
					'placeholder' => 'https://twitter.com/… / https://bsky.app/…'],
			],
			styleFields: [
				['key' => 'aspectRatio', 'label' => 'Aspect ratio', 'type' => 'select',
					'options' => [['value' => '16:9', 'label' => '16:9'], ['value' => 'original', 'label' => 'Original']],
					'default' => 'original'],
			],
			renderTemplate: 'blocks/social_embed.twig',
			editorComponent: 'SocialEmbedBlock',
			elementFields: $this->elementFields(),
			defaults: ['content' => ['url' => ''], 'style' => ['base' => ['aspectRatio' => 'original']]],
		);
	}

	private function query(): BlockType
	{
		return new BlockType(
			type: 'query',
			label: 'Query',
			category: 'content',
			// Children act as a per-result template: `BlockRenderer` executes the
			// query and renders the once-stored child list for every result item,
			// resolving each child's `bindings` (dynamic field mappings) per item.
			acceptsChildren: true,
			allowedChildTypes: [], // empty = any block type allowed
			contentFields: [
				['key' => 'source', 'label' => 'Source', 'type' => 'select',
					'options' => [['value' => 'objects', 'label' => 'Objects']], 'default' => 'objects', 'required' => true],
				['key' => 'objectType', 'label' => 'Object type', 'type' => 'text',
					'placeholder' => 'e.g. page'],
				['key' => 'filters', 'label' => 'Filters', 'type' => 'filters', 'of' => 'objectType'],
				['key' => 'limit', 'label' => 'Limit', 'type' => 'number', 'default' => 10],
				['key' => 'layout', 'label' => 'Layout', 'type' => 'select',
					'options' => [['value' => 'list', 'label' => 'List'], ['value' => 'grid', 'label' => 'Grid']],
					'default' => 'list'],
			],
			styleFields: [
				['key' => 'columns', 'label' => 'Columns', 'type' => 'number', 'default' => 1],
				['key' => 'gap', 'label' => 'Gap', 'type' => 'spacing'],
			],
			renderTemplate: 'blocks/query.twig',
			editorComponent: 'QueryBlock',
			elementFields: $this->elementFields(),
			defaults: ['content' => ['source' => 'objects', 'objectType' => '', 'filters' => [], 'limit' => 10, 'layout' => 'list'],
				'style' => ['base' => ['columns' => 1]]],
		);
	}
}
