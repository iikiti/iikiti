<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Selects per-widget HTTP fragments without expanding query-row templates. */
final class WidgetFragmentPlanner
{
	public function __construct(
		private readonly BlockTypeRegistry $registry,
		#[Autowire('%widget_fragment_hmac_key%')]
		private readonly string $secret = '01234567890123456789012345678901',
	) {
		if (32 !== strlen($secret)) {
			throw new \InvalidArgumentException('The widget fragment HMAC key must contain exactly 32 bytes.');
		}
	}

	/**
	 * @param list<array{kind:string,id:string,role:string,tree:list<array<string,mixed>>}> $roots
	 */
	public function plan(array $roots, BlockRenderContext $context, string $revision, int $budget): WidgetFragmentPlan
	{
		$descriptors = [];
		$entriesById = [];
		$descriptorsByPath = [];
		$remaining = max(0, $budget);

		foreach ($roots as $root) {
			$rootPath = WidgetFragmentPlan::rootPath($root['kind'], $root['id']);
			$this->visitNodes(
				$root['tree'],
				$context,
				$root,
				$rootPath,
				0,
				$revision,
				$remaining,
				$descriptors,
				$entriesById,
				$descriptorsByPath,
			);
		}

		return new WidgetFragmentPlan($descriptors, $entriesById, $descriptorsByPath);
	}

	/**
	 * @param list<array<string,mixed>>                                                                                                                                                     $nodes
	 * @param array{kind:string,id:string,role:string,tree:list<array<string,mixed>>}                                                                                                       $root
	 * @param list<array{kind:string,id:string,role:string,slot:string,depth:int}>                                                                                                          $descriptors
	 * @param array<string,array{node:array<string,mixed>,path:string,root_kind:string,root_id:string,depth:int,descriptor:array{kind:string,id:string,role:string,slot:string,depth:int}}> $entriesById
	 * @param array<string,array{kind:string,id:string,role:string,slot:string,depth:int}>                                                                                                  $descriptorsByPath
	 */
	private function visitNodes(
		array $nodes,
		BlockRenderContext $context,
		array $root,
		string $parentPath,
		int $widgetDepth,
		string $revision,
		int &$remaining,
		array &$descriptors,
		array &$entriesById,
		array &$descriptorsByPath,
	): void {
		foreach ($nodes as $index => $node) {
			$type = (string) ($node['type'] ?? '');
			if (0 === $widgetDepth && !$context->editorMode && !RootContainerRule::isAllowedAtRoot($type)) {
				continue;
			}

			$path = WidgetFragmentPlan::childPath($parentPath, (int) $index);
			$blockType = $this->registry->get($type);
			if (null === $blockType) {
				continue;
			}

			$mode = $node['renderMode'] ?? 'default';
			if (!in_array($mode, ['default', 'always', 'never'], true)) {
				$mode = 'default';
			}
			$eligible = null !== $blockType->renderTemplate && (
				'always' === $mode || ('default' === $mode && 0 === $widgetDepth)
			);

			if ($eligible && $remaining > 0) {
				$descriptor = $this->descriptorFor($path, $revision, $widgetDepth + 1);
				$descriptors[] = $descriptor;
				$entriesById[$descriptor['id']] = [
					'node' => $node,
					'path' => $path,
					'root_kind' => $root['kind'],
					'root_id' => $root['id'],
					'depth' => $widgetDepth,
					'descriptor' => $descriptor,
				];
				$descriptorsByPath[$path] = $descriptor;
				--$remaining;
			}

			// Query children are a per-row rendering context and cannot be safely
			// resolved by an independent fragment request.
			if (!$blockType->acceptsChildren || 'query' === $type) {
				continue;
			}

			$children = $node['children'] ?? [];
			if ('dynamic' === $type) {
				$dynamicId = (string) ($node['id'] ?? '');
				$children = $context->dynamicBlocks[$dynamicId] ?? $children;
			}
			if (is_array($children)) {
				$this->visitNodes(
					$children,
					$context,
					$root,
					$path,
					$widgetDepth + 1,
					$revision,
					$remaining,
					$descriptors,
					$entriesById,
					$descriptorsByPath,
				);
			}
		}
	}

	/** @return array{kind:string,id:string,role:string,slot:string,depth:int} */
	private function descriptorFor(string $path, string $revision, int $depth): array
	{
		$id = hash_hmac('sha256', $revision."\0".$path, $this->secret);
		$slot = substr(hash('sha256', "slot\0".$revision."\0".$path), 0, 32);

		return [
			'kind' => 'widget',
			'id' => $id,
			'role' => 'widget',
			'slot' => $slot,
			'depth' => $depth,
		];
	}
}
