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
	#[\Override]
	public function getBlockTypes(): array
	{
		return [
			$this->container(),
			$this->dynamic(),
			$this->heading(),
			$this->text(),
			$this->image(),
			$this->videoEmbed(),
			$this->socialEmbed(),
			$this->query(),
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
			defaults: ['content' => ['label' => 'Dynamic content region']],
		);
	}

	private function heading(): BlockType
	{
		return new BlockType(
			type: 'heading',
			label: 'Heading',
			category: 'text',
			acceptsChildren: false,
			contentFields: [
				['key' => 'text', 'label' => 'Text', 'type' => 'text', 'required' => true],
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
			defaults: ['content' => ['level' => '2', 'text' => ''], 'style' => ['base' => []]],
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
			defaults: ['content' => ['url' => ''], 'style' => ['base' => ['aspectRatio' => 'original']]],
		);
	}

	private function query(): BlockType
	{
		return new BlockType(
			type: 'query',
			label: 'Query',
			category: 'content',
			acceptsChildren: false,
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
			defaults: ['content' => ['source' => 'objects', 'objectType' => '', 'filters' => [], 'limit' => 10, 'layout' => 'list'],
				'style' => ['base' => ['columns' => 1]]],
		);
	}
}
