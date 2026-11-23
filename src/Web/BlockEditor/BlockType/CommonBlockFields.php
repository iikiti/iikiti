<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\BlockType;

/**
 * Shared baseline element and style field definitions reused by core and
 * first-party plugin block type providers.
 */
final class CommonBlockFields
{
	/**
	 * @return list<array<string,mixed>>
	 */
	public static function elementFields(): array
	{
		return [
			['key' => 'id', 'label' => 'ID', 'type' => 'text', 'placeholder' => 'element-id'],
			['key' => 'cssClass', 'label' => 'CSS class', 'type' => 'text', 'placeholder' => 'space-separated classes'],
		];
	}

	public static function withCommonStyleFields(BlockType $type): BlockType
	{
		$commonFields = [];
		foreach (self::commonStyleFields() as $field) {
			$commonFields[(string) $field['key']] = $field;
		}

		$fields = [];
		$legacyKeys = ['layout', 'align', 'size'];
		$extraGroups = ['columns' => ['layout', 60], 'aspectRatio' => ['size', 70]];
		foreach ($type->styleFields as $field) {
			$key = (string) ($field['key'] ?? '');
			if (in_array($key, $legacyKeys, true)) {
				continue;
			}
			if (isset($commonFields[$key])) {
				$merged = array_replace($commonFields[$key], array_intersect_key($field, array_flip(['default', 'options', 'min', 'max', 'step'])));
				$fields[$key] = $merged;
				unset($commonFields[$key]);
				continue;
			}
			[$group, $order] = $extraGroups[$key] ?? ['general', 100];
			$fields[$key] = array_replace($field, ['group' => $group, 'order' => $order]);
		}

		foreach ($commonFields as $key => $field) {
			$fields[$key] = $field;
		}
		$styleFields = array_values($fields);

		return new BlockType(
			type: $type->type,
			label: $type->label,
			category: $type->category,
			acceptsChildren: $type->acceptsChildren,
			allowedChildTypes: $type->allowedChildTypes,
			contentFields: $type->contentFields,
			styleFields: $styleFields,
			renderTemplate: $type->renderTemplate,
			editorComponent: $type->editorComponent,
			defaults: $type->defaults,
			elementFields: $type->elementFields,
			source: $type->source,
			wrapperTag: $type->wrapperTag,
			childrenInWrapper: $type->childrenInWrapper,
		);
	}

	/** @return list<array<string,mixed>> */
	public static function commonStyleFields(): array
	{
		return [
			['key' => 'display', 'label' => 'Display', 'type' => 'select', 'group' => 'layout', 'order' => 10, 'options' => [
				['value' => 'block', 'label' => 'Block'], ['value' => 'inline', 'label' => 'Inline'],
				['value' => 'inline-block', 'label' => 'Inline block'], ['value' => 'flex', 'label' => 'Flex'],
				['value' => 'inline-flex', 'label' => 'Inline flex'], ['value' => 'grid', 'label' => 'Grid'],
				['value' => 'inline-grid', 'label' => 'Inline grid'],
			]],
			['key' => 'flexDirection', 'label' => 'Direction', 'type' => 'select', 'group' => 'layout', 'order' => 20, 'options' => [
				['value' => 'row', 'label' => 'Row'], ['value' => 'row-reverse', 'label' => 'Row reverse'],
				['value' => 'column', 'label' => 'Column'], ['value' => 'column-reverse', 'label' => 'Column reverse'],
			]],
			['key' => 'justifyContent', 'label' => 'Justify content', 'type' => 'select', 'group' => 'layout', 'order' => 30, 'options' => [
				['value' => 'flex-start', 'label' => 'Start'], ['value' => 'center', 'label' => 'Center'],
				['value' => 'flex-end', 'label' => 'End'], ['value' => 'space-between', 'label' => 'Space between'],
				['value' => 'space-around', 'label' => 'Space around'], ['value' => 'space-evenly', 'label' => 'Space evenly'],
			]],
			['key' => 'alignItems', 'label' => 'Align items', 'type' => 'select', 'group' => 'layout', 'order' => 40, 'options' => [
				['value' => 'stretch', 'label' => 'Stretch'], ['value' => 'flex-start', 'label' => 'Start'],
				['value' => 'center', 'label' => 'Center'], ['value' => 'flex-end', 'label' => 'End'],
				['value' => 'baseline', 'label' => 'Baseline'],
			]],
			['key' => 'width', 'label' => 'Width', 'type' => 'cssLength', 'group' => 'size', 'order' => 10, 'placeholder' => 'e.g. 100%, 20rem'],
			['key' => 'height', 'label' => 'Height', 'type' => 'cssLength', 'group' => 'size', 'order' => 20, 'placeholder' => 'e.g. auto, 240px'],
			['key' => 'minWidth', 'label' => 'Minimum width', 'type' => 'cssLength', 'group' => 'size', 'order' => 30],
			['key' => 'maxWidth', 'label' => 'Maximum width', 'type' => 'cssLength', 'group' => 'size', 'order' => 40],
			['key' => 'minHeight', 'label' => 'Minimum height', 'type' => 'cssLength', 'group' => 'size', 'order' => 50],
			['key' => 'maxHeight', 'label' => 'Maximum height', 'type' => 'cssLength', 'group' => 'size', 'order' => 60],
			['key' => 'aspectRatio', 'label' => 'Aspect ratio', 'type' => 'select', 'group' => 'size', 'order' => 70, 'options' => [
				['value' => 'auto', 'label' => 'Auto'], ['value' => '16:9', 'label' => '16:9'],
				['value' => '4:3', 'label' => '4:3'], ['value' => '1:1', 'label' => '1:1'],
			]],
			['key' => 'margin', 'label' => 'Margin', 'type' => 'cssLength', 'group' => 'spacing', 'order' => 10, 'placeholder' => 'e.g. 1rem 0'],
			['key' => 'padding', 'label' => 'Padding', 'type' => 'cssLength', 'group' => 'spacing', 'order' => 20],
			['key' => 'gap', 'label' => 'Gap', 'type' => 'cssLength', 'group' => 'spacing', 'order' => 30],
			['key' => 'rowGap', 'label' => 'Row gap', 'type' => 'cssLength', 'group' => 'spacing', 'order' => 40],
			['key' => 'columnGap', 'label' => 'Column gap', 'type' => 'cssLength', 'group' => 'spacing', 'order' => 50],
			['key' => 'color', 'label' => 'Text color', 'type' => 'color', 'group' => 'typography', 'order' => 10],
			['key' => 'fontSize', 'label' => 'Font size', 'type' => 'cssLength', 'group' => 'typography', 'order' => 20],
			['key' => 'fontWeight', 'label' => 'Font weight', 'type' => 'select', 'group' => 'typography', 'order' => 30, 'options' => [
				['value' => '100', 'label' => '100'], ['value' => '200', 'label' => '200'], ['value' => '300', 'label' => '300'],
				['value' => '400', 'label' => '400'], ['value' => '500', 'label' => '500'], ['value' => '600', 'label' => '600'],
				['value' => '700', 'label' => '700'], ['value' => '800', 'label' => '800'], ['value' => '900', 'label' => '900'],
			]],
			['key' => 'lineHeight', 'label' => 'Line height', 'type' => 'cssLength', 'group' => 'typography', 'order' => 40],
			['key' => 'textAlign', 'label' => 'Text alignment', 'type' => 'select', 'group' => 'typography', 'order' => 50, 'options' => [
				['value' => 'start', 'label' => 'Start'], ['value' => 'center', 'label' => 'Center'],
				['value' => 'end', 'label' => 'End'], ['value' => 'justify', 'label' => 'Justify'],
			]],
			['key' => 'backgroundColor', 'label' => 'Color', 'type' => 'color', 'group' => 'background', 'order' => 10],
			['key' => 'borderWidth', 'label' => 'Width', 'type' => 'cssLength', 'group' => 'borders', 'order' => 10],
			['key' => 'borderStyle', 'label' => 'Style', 'type' => 'select', 'group' => 'borders', 'order' => 20, 'options' => [
				['value' => 'none', 'label' => 'None'], ['value' => 'solid', 'label' => 'Solid'],
				['value' => 'dashed', 'label' => 'Dashed'], ['value' => 'dotted', 'label' => 'Dotted'],
			]],
			['key' => 'borderColor', 'label' => 'Color', 'type' => 'color', 'group' => 'borders', 'order' => 30],
			['key' => 'borderRadius', 'label' => 'Radius', 'type' => 'cssLength', 'group' => 'borders', 'order' => 40],
			['key' => 'opacity', 'label' => 'Opacity', 'type' => 'number', 'group' => 'effects', 'order' => 10, 'min' => 0, 'max' => 1, 'step' => 0.05],
			['key' => 'boxShadow', 'label' => 'Shadow', 'type' => 'select', 'group' => 'effects', 'order' => 20, 'options' => [
				['value' => 'none', 'label' => 'None'], ['value' => '0 1px 2px rgba(0, 0, 0, 0.12)', 'label' => 'Small'],
				['value' => '0 4px 12px rgba(0, 0, 0, 0.16)', 'label' => 'Medium'],
				['value' => '0 12px 32px rgba(0, 0, 0, 0.2)', 'label' => 'Large'],
			]],
			['key' => 'position', 'label' => 'Position', 'type' => 'select', 'group' => 'position', 'order' => 10, 'options' => [
				['value' => 'static', 'label' => 'Static'], ['value' => 'relative', 'label' => 'Relative'],
				['value' => 'absolute', 'label' => 'Absolute'], ['value' => 'fixed', 'label' => 'Fixed'],
				['value' => 'sticky', 'label' => 'Sticky'],
			]],
			['key' => 'top', 'label' => 'Top', 'type' => 'cssLength', 'group' => 'position', 'order' => 20],
			['key' => 'right', 'label' => 'Right', 'type' => 'cssLength', 'group' => 'position', 'order' => 30],
			['key' => 'bottom', 'label' => 'Bottom', 'type' => 'cssLength', 'group' => 'position', 'order' => 40],
			['key' => 'left', 'label' => 'Left', 'type' => 'cssLength', 'group' => 'position', 'order' => 50],
			['key' => 'zIndex', 'label' => 'Z-index', 'type' => 'number', 'group' => 'position', 'order' => 60],
		];
	}
}
