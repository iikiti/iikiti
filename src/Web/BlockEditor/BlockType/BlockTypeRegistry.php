<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\BlockType;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginActivationChecker;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Registry of all available block types, aggregated from all tagged
 * {@see BlockTypeInterface} implementations (core + plugins).
 */
final class BlockTypeRegistry implements ResetInterface
{
	/** @var array<string,BlockType>|null */
	private ?array $byType = null;

	/**
	 * @param iterable<BlockTypeInterface> $providers
	 */
	public function __construct(
		#[AutowireIterator('iikiti.cms.block_type')]
		private readonly iterable $providers,
		private readonly ?PluginActivationChecker $activationChecker = null,
	) {
	}

	#[\Override]
	public function reset(): void
	{
		$this->byType = null;
	}

	public function get(string $type): ?BlockType
	{
		$blockType = $this->loadBlockTypes()[$type] ?? null;
		if (null === $blockType) {
			return null;
		}

		return $this->isBlockTypeActive($blockType) ? $blockType : null;
	}

	/**
	 * @return array<string,BlockType>
	 */
	public function all(): array
	{
		return $this->allForSite();
	}

	/**
	 * @return array<string,BlockType>
	 */
	public function allForSite(?Site $site = null): array
	{
		$byType = $this->loadBlockTypes();
		if (null === $this->activationChecker) {
			return $byType;
		}

		$active = [];
		foreach ($byType as $key => $blockType) {
			if ($this->isBlockTypeActive($blockType, $site)) {
				$active[$key] = $blockType;
			}
		}

		return $active;
	}

	/**
	 * @return array<string,BlockType>
	 */
	private function loadBlockTypes(): array
	{
		if (null !== $this->byType) {
			return $this->byType;
		}

		$loaded = [];
		foreach ($this->providers as $provider) {
			foreach ($provider->getBlockTypes() as $blockType) {
				$loaded[$blockType->type] = $blockType;
			}
		}

		return $this->byType = $loaded;
	}

	/**
	 * @return list<BlockType>
	 */
	public function allList(): array
	{
		return array_values($this->all());
	}

	/**
	 * @return list<string> Block type ids a child of `$parentType` may be.
	 *                      Empty list = none; `null` (parent is not a container) = none.
	 */
	public function getAllowedChildTypes(string $parentType): ?array
	{
		$type = $this->get($parentType);

		return $type?->allowedChildTypes;
	}

	/**
	 * @return list<BlockType> types that are allowed as a child of `$parentType`,
	 *                         filtered to those registered and active
	 */
	public function getAllowedChildrenFor(string $parentType): array
	{
		$allowed = $this->getAllowedChildTypes($parentType);
		if (null === $allowed) {
			return [];
		}

		$active = $this->all();
		$out = [];
		foreach ($allowed as $childType) {
			if (isset($active[$childType])) {
				$out[] = $active[$childType];
			}
		}

		// Empty array = any active block type may be a child.
		return [] === $out && [] === $allowed ?
			array_values($active) :
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
		foreach ($this->all() as $blockType) {
			$byCategory[$blockType->category][] = $blockType;
		}
		foreach ($byCategory as &$list) {
			usort($list, static fn (BlockType $a, BlockType $b): int => $a->label <=> $b->label);
		}

		return $byCategory;
	}

	private function isBlockTypeActive(BlockType $blockType, ?Site $site = null): bool
	{
		if (null === $this->activationChecker || 'core' === $blockType->source || '' === $blockType->source) {
			return true;
		}

		return $this->activationChecker->isActiveForSite($blockType->source, $site);
	}
}
