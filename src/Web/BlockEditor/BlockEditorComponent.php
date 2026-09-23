<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor;

use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\Interfaces\ComponentInterface;

/**
 * Editor-facing facade over the {@see BlockTypeRegistry}.
 */
class BlockEditorComponent implements ComponentInterface
{
	public function __construct(private readonly BlockTypeRegistry $registry)
	{
	}

	#[\Override]
	public function getContainerList(): array
	{
		$containers = [];
		foreach ($this->registry->allList() as $blockType) {
			if ($blockType->acceptsChildren) {
				$containers[] = [
					'type' => $blockType->type,
					'label' => $blockType->label,
					'allowed_child_types' => $blockType->childTypes(),
				];
			}
		}

		return $containers;
	}

	/**
	 * @return list<array{type:string, label:string, category:string, acceptsChildren:bool, allowedChildTypes:list<string>|null, contentFields:list<array<string,mixed>>, styleFields:list<array<string,mixed>>, editorComponent?:string|null}>
	 */
	public function getBlockTypes(): array
	{
		$out = [];
		foreach ($this->registry->allList() as $blockType) {
			$out[] = [
				'type' => $blockType->type,
				'label' => $blockType->label,
				'category' => $blockType->category,
				'acceptsChildren' => $blockType->acceptsChildren,
				'allowedChildTypes' => $blockType->childTypes(),
				'contentFields' => $blockType->contentFields,
				'styleFields' => $blockType->styleFields,
				'editorComponent' => $blockType->editorComponent,
			];
		}

		return $out;
	}

	#[\Override]
	public function getSettingsFields(string $blockType): ?array
	{
		$type = $this->registry->get($blockType);
		if (null === $type) {
			return null;
		}

		return array_merge($type->contentFields, $type->styleFields);
	}
}
