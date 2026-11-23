<?php

declare(strict_types=1);

namespace iikiti\Extension\Forms\BlockType;

use iikiti\CMS\Web\BlockEditor\BlockType\BlockType;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeInterface;
use iikiti\CMS\Web\BlockEditor\BlockType\CommonBlockFields;

/**
 * Provides semantic HTML form and form-control block types.
 */
final class FormBlockTypeProvider implements BlockTypeInterface
{
	#[\Override]
	public function getBlockTypes(): array
	{
		return array_map(
			CommonBlockFields::withCommonStyleFields(...),
			[
				$this->form(),
				$this->button(),
				$this->input(),
				$this->textArea(),
				$this->select(),
				$this->range(),
				$this->checkbox(),
				$this->radio(),
				$this->legend(),
				$this->fieldset(),
			],
		);
	}

	private function form(): BlockType
	{
		return new BlockType(
			type: 'form',
			label: 'Form',
			category: 'form',
			acceptsChildren: true,
			allowedChildTypes: ['input', 'textarea', 'select', 'range', 'checkbox', 'radio', 'button', 'fieldset'],
			contentFields: [
				['key' => 'action', 'label' => 'Action URL', 'type' => 'url', 'wrapperAttribute' => true],
				['key' => 'method', 'label' => 'Method', 'type' => 'select',
					'options' => [['value' => 'post', 'label' => 'POST'], ['value' => 'get', 'label' => 'GET']],
					'default' => 'post', 'wrapperAttribute' => true],
			],
			renderTemplate: '@Forms/blocks/form.twig',
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
			wrapperTag: 'form',
			childrenInWrapper: true,
		);
	}

	private function button(): BlockType
	{
		return new BlockType(
			type: 'button',
			label: 'Button',
			category: 'form',
			contentFields: [
				['key' => 'text', 'label' => 'Text', 'type' => 'text', 'default' => 'Button'],
				['key' => 'type', 'label' => 'Type', 'type' => 'select',
					'options' => [['value' => 'button', 'label' => 'Button'], ['value' => 'submit', 'label' => 'Submit']],
					'default' => 'button'],
			],
			renderTemplate: '@Forms/blocks/button.twig',
			defaults: ['content' => ['text' => 'Button', 'type' => 'button']],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
		);
	}

	private function input(): BlockType
	{
		return new BlockType(
			type: 'input',
			label: 'Input',
			category: 'form',
			contentFields: [
				['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Input'],
				['key' => 'name', 'label' => 'Name', 'type' => 'text'],
				['key' => 'type', 'label' => 'Type', 'type' => 'select',
					'options' => [['value' => 'text', 'label' => 'Text'], ['value' => 'password', 'label' => 'Password'],
						['value' => 'email', 'label' => 'Email'], ['value' => 'number', 'label' => 'Number']], 'default' => 'text'],
				['key' => 'value', 'label' => 'Value', 'type' => 'text'],
				['key' => 'placeholder', 'label' => 'Placeholder', 'type' => 'text'],
				['key' => 'required', 'label' => 'Required', 'type' => 'toggle', 'default' => false],
				['key' => 'min', 'label' => 'Minimum', 'type' => 'number'],
				['key' => 'max', 'label' => 'Maximum', 'type' => 'number'],
				['key' => 'step', 'label' => 'Step', 'type' => 'number'],
			],
			renderTemplate: '@Forms/blocks/input.twig',
			defaults: ['content' => ['label' => 'Input', 'type' => 'text', 'value' => '', 'required' => false]],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
		);
	}

	private function textArea(): BlockType
	{
		return new BlockType(
			type: 'textarea',
			label: 'Text Area',
			category: 'form',
			contentFields: [
				['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Text Area'],
				['key' => 'name', 'label' => 'Name', 'type' => 'text'],
				['key' => 'value', 'label' => 'Value', 'type' => 'textarea'],
				['key' => 'placeholder', 'label' => 'Placeholder', 'type' => 'text'],
				['key' => 'rows', 'label' => 'Rows', 'type' => 'number', 'default' => 4],
				['key' => 'required', 'label' => 'Required', 'type' => 'toggle', 'default' => false],
			],
			renderTemplate: '@Forms/blocks/textarea.twig',
			defaults: ['content' => ['label' => 'Text Area', 'value' => '', 'rows' => 4, 'required' => false]],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
		);
	}

	private function select(): BlockType
	{
		return new BlockType(
			type: 'select',
			label: 'Select',
			category: 'form',
			contentFields: [
				['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Select'],
				['key' => 'name', 'label' => 'Name', 'type' => 'text'],
				['key' => 'value', 'label' => 'Selected value', 'type' => 'text'],
				['key' => 'required', 'label' => 'Required', 'type' => 'toggle', 'default' => false],
				['key' => 'options', 'label' => 'Options', 'type' => 'repeater', 'itemLabel' => 'option', 'addLabel' => 'Add option',
					'fields' => [['key' => 'label', 'label' => 'Label', 'type' => 'text'], ['key' => 'value', 'label' => 'Value', 'type' => 'text']],
					'default' => [['label' => 'Choose an option', 'value' => '']]],
			],
			renderTemplate: '@Forms/blocks/select.twig',
			defaults: ['content' => ['label' => 'Select', 'value' => '', 'required' => false,
				'options' => [['label' => 'Choose an option', 'value' => '']]]],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
		);
	}

	private function range(): BlockType
	{
		return new BlockType(
			type: 'range',
			label: 'Range',
			category: 'form',
			contentFields: [
				['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Range'],
				['key' => 'name', 'label' => 'Name', 'type' => 'text'],
				['key' => 'min', 'label' => 'Minimum', 'type' => 'number', 'default' => 0],
				['key' => 'max', 'label' => 'Maximum', 'type' => 'number', 'default' => 100],
				['key' => 'step', 'label' => 'Step', 'type' => 'number', 'default' => 1],
				['key' => 'value', 'label' => 'Value', 'type' => 'number', 'default' => 50],
			],
			renderTemplate: '@Forms/blocks/range.twig',
			defaults: ['content' => ['label' => 'Range', 'min' => 0, 'max' => 100, 'step' => 1, 'value' => 50]],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
		);
	}

	private function checkbox(): BlockType
	{
		return new BlockType(
			type: 'checkbox',
			label: 'Checkbox',
			category: 'form',
			contentFields: [
				['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Checkbox'],
				['key' => 'name', 'label' => 'Name', 'type' => 'text'],
				['key' => 'value', 'label' => 'Value', 'type' => 'text', 'default' => '1'],
				['key' => 'checked', 'label' => 'Checked', 'type' => 'toggle', 'default' => false],
				['key' => 'required', 'label' => 'Required', 'type' => 'toggle', 'default' => false],
			],
			renderTemplate: '@Forms/blocks/checkbox.twig',
			defaults: ['content' => ['label' => 'Checkbox', 'value' => '1', 'checked' => false, 'required' => false]],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
		);
	}

	private function radio(): BlockType
	{
		return new BlockType(
			type: 'radio',
			label: 'Radio',
			category: 'form',
			contentFields: [
				['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Radio'],
				['key' => 'name', 'label' => 'Name', 'type' => 'text'],
				['key' => 'value', 'label' => 'Value', 'type' => 'text', 'default' => 'option'],
				['key' => 'checked', 'label' => 'Checked', 'type' => 'toggle', 'default' => false],
				['key' => 'required', 'label' => 'Required', 'type' => 'toggle', 'default' => false],
			],
			renderTemplate: '@Forms/blocks/radio.twig',
			defaults: ['content' => ['label' => 'Radio', 'value' => 'option', 'checked' => false, 'required' => false]],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
		);
	}

	private function legend(): BlockType
	{
		return new BlockType(
			type: 'legend',
			label: 'Legend',
			category: 'form',
			contentFields: [['key' => 'text', 'label' => 'Text', 'type' => 'text', 'default' => 'Legend']],
			renderTemplate: '@Forms/blocks/legend.twig',
			defaults: ['content' => ['text' => 'Legend']],
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
			wrapperTag: 'legend',
		);
	}

	private function fieldset(): BlockType
	{
		return new BlockType(
			type: 'fieldset',
			label: 'Fieldset',
			category: 'form',
			acceptsChildren: true,
			allowedChildTypes: ['legend', 'input', 'textarea', 'select', 'range', 'checkbox', 'radio', 'button'],
			renderTemplate: '@Forms/blocks/fieldset.twig',
			elementFields: CommonBlockFields::elementFields(),
			source: 'iikiti-forms',
			wrapperTag: 'fieldset',
			childrenInWrapper: true,
		);
	}
}
