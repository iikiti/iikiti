<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Usage;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;

/**
 * On-demand block usage index: walks every template block tree (`blocks` +
 * `blocks_draft`) and every object dynamic block tree (`dynamic_blocks` +
 * `dynamic_blocks_draft`) and aggregates per-type counts with the templates /
 * objects that reference each type.
 *
 * Template→page assignment resolution is intentionally out of scope (it belongs
 * to the dashboard page that consumes this data).
 *
 * Block trees are stored as JSON properties and may be malformed, so they are
 * walked defensively as `mixed` rather than relying on their declared types.
 */
final class BlockUsageIndexer implements BlockUsageIndexInterface
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly BlockTypeRegistry $registry,
	) {
	}

	#[\Override]
	public function usage(): array
	{
		/** @var array<string, array{count: int, usedBy: array<string, array<string, true>>}> $counts */
		$counts = [];

		foreach ($this->entityManager->getRepository(Template::class)->findAll() as $template) {
			$contextId = (string) $template->getId();
			foreach ([$template->getBlocks(), $template->getBlocksDraft()] as $trees) {
				/** @var mixed $trees */
				if (!is_array($trees)) {
					continue;
				}
				foreach ($trees as $nodes) {
					/** @var mixed $nodes */
					if (is_array($nodes)) {
						$counts = $this->walk($nodes, 'template', $contextId, $counts);
					}
				}
			}
		}

		foreach (
			$this->entityManager->getRepository(ObjectProperty::class)->findBy([
				'name' => ['dynamic_blocks', 'dynamic_blocks_draft'],
			]) as $property
		) {
			$value = $property->getValue();
			if (!is_array($value)) {
				continue;
			}
			$contextId = (string) ($property->getObject()?->getId() ?? '');
			foreach ($value as $nodes) {
				/** @var mixed $nodes */
				if (is_array($nodes)) {
					$counts = $this->walk($nodes, 'object', $contextId, $counts);
				}
			}
		}

		$out = [];
		foreach ($counts as $type => $data) {
			$usedBy = [];
			foreach ($data['usedBy'] as $contextType => $ids) {
				foreach (array_keys($ids) as $id) {
					if ('' === $id) {
						continue;
					}
					$usedBy[] = ['contextType' => $contextType, 'contextId' => (int) $id];
				}
			}
			$blockType = $this->registry->get($type);
			$out[] = [
				'type' => $type,
				'label' => $blockType ? $blockType->label : $type,
				'count' => $data['count'],
				'usedBy' => $usedBy,
			];
		}

		usort(
			$out,
			static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['type'], $b['type']),
		);

		return $out;
	}

	/**
	 * @param list<mixed>                                                                  $nodes
	 * @param array<string, array{count: int, usedBy: array<string, array<string, true>>}> $counts
	 *
	 * @return array<string, array{count: int, usedBy: array<string, array<string, true>>}>
	 */
	private function walk(array $nodes, string $contextType, string $contextId, array $counts): array
	{
		foreach ($nodes as $node) {
			/** @var mixed $node */
			if (!is_array($node)) {
				continue;
			}
			$type = (string) ($node['type'] ?? '');
			if ('' !== $type) {
				$counts[$type]['count'] = ($counts[$type]['count'] ?? 0) + 1;
				$counts[$type]['usedBy'][$contextType][$contextId] = true;
			}
			$children = $node['children'] ?? null;
			if (is_array($children)) {
				$counts = $this->walk($children, $contextType, $contextId, $counts);
			}
		}

		return $counts;
	}
}
