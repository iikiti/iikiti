<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

/** Concatenates ordered JSON list pieces without mixing object/list semantics. */
final class JsonArrayComposer
{
	/** @var list<array{piece:list<mixed>,order:int,sequence:int}> */
	private array $pieces = [];
	private int $sequence = 0;

	/** @param array<mixed> $piece */
	public function add(array $piece, int $order = 0): void
	{
		if (!array_is_list($piece)) {
			throw new \InvalidArgumentException('JSON array pieces must be list arrays.');
		}

		$this->pieces[] = ['piece' => $piece, 'order' => $order, 'sequence' => $this->sequence++];
	}

	/** @return list<mixed> */
	public function compose(): array
	{
		$pieces = $this->pieces;
		usort($pieces, static fn (array $left, array $right): int =>
			$left['order'] <=> $right['order'] ?: $left['sequence'] <=> $right['sequence']);

		$result = [];
		foreach ($pieces as $entry) {
			foreach ($entry['piece'] as $value) {
				$result[] = $value;
			}
		}

		return $result;
	}
}
