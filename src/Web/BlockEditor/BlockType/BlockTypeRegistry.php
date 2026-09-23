<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\BlockType;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry of all available block types, aggregated from all tagged
 * {@see BlockTypeInterface} implementations (core + plugins).
 */
final class BlockTypeRegistry
{
	/** @var array<string,BlockType> */
	private array $byType = [];

	/**
	 * @param iterable<BlockTypeInterface> $providers
	 */
	public function __construct(
		#[AutowireIterator('iikiti.cms.block_type')]
		private readonly iterable $providers,
	) {
		// Force collection at construction so tagging errors surface early.
		foreach ($this->providers as $provider) {
			foreach ($provider->getBlockTypes() as $blockType) {
				$this->byType[$blockType->type] = $blockType;
			}
		}
	}

	public function get(string $type): ?BlockType
	{
		return $this->byType[$type] ?? null;
	}

	/**
	 * @return array<string,BlockType>
	 */
	public function all(): array
	{
		return $this->byType;
	}

	/**
	 * @return list<BlockType>
	 */
	public function allList(): array
	{
		return array_values($this->byType);
	}

	/**
	 * @return list<string> Block type ids a child of `$parentType` may be.
	 *                      Empty list = none; `null` (parent is not a container) = none.
	 */
	public function getAllowedChildTypes(string $parentType): ?array
	{
		$type = $this->byType[$parentType] ?? null;

		return $type?->allowedChildTypes;
	}

	/**
	 * @return list<BlockType> types that are allowed as a child of `$parentType`,
	 *                         filtered to those registered
	 */
	public function getAllowedChildrenFor(string $parentType): array
	{
		$allowed = $this->getAllowedChildTypes($parentType);
		if (null === $allowed) {
			return [];
		}

		$out = [];
		foreach ($allowed as $childType) {
			if (isset($this->byType[$childType])) {
				$out[] = $this->byType[$childType];
			}
		}

		// Empty array = any block type may be a child.
		return [] === $out && [] === $allowed ?
			array_values($this->byType) :
			$out;
	}

	/**
	 * Block types grouped by category for the editor's "add block" palette.
	 *
	 * @return array<string, list<BlockType>>
	 */
	public function byCategory(): array
	{
		$byCategory = [];
		foreach ($this->byType as $blockType) {
			$byCategory[$blockType->category][] = $blockType;
		}
		foreach ($byCategory as &$list) {
			usort($list, static fn (BlockType $a, BlockType $b): int => $a->label <=> $b->label);
		}

		return $byCategory;
	}
}
