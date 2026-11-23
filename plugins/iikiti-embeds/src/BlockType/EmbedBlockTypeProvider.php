<?php

declare(strict_types=1);

namespace iikiti\Extension\Embeds\BlockType;

use iikiti\CMS\Web\BlockEditor\BlockType\BlockType;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeInterface;
use iikiti\CMS\Web\BlockEditor\BlockType\CommonBlockFields;

/**
 * Provides the `video_embed` and `social_embed` blocks.
 */
final class EmbedBlockTypeProvider implements BlockTypeInterface
{
	#[\Override]
	public function getBlockTypes(): array
	{
		return array_map(
			CommonBlockFields::withCommonStyleFields(...),
			[
				$this->videoEmbed(),
				$this->socialEmbed(),
			],
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
			renderTemplate: '@Embeds/blocks/video_embed.twig',
			editorComponent: 'VideoEmbedBlock',
			defaults: ['content' => ['url' => ''], 'style' => ['base' => ['aspectRatio' => '16:9']]],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-embeds',
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
			renderTemplate: '@Embeds/blocks/social_embed.twig',
			editorComponent: 'SocialEmbedBlock',
			defaults: ['content' => ['url' => ''], 'style' => ['base' => ['aspectRatio' => 'original']]],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-embeds',
		);
	}
}
