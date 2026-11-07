<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

use LogicException;

/** Composes ordered JSON object pieces and rejects duplicate top-level keys. */
final class JsonObjectComposer
{
	/** @var list<array{piece:array<string,mixed>,order:int,sequence:int}> */
	private array $pieces = [];
	private int $sequence = 0;

	/** @param array<array-key,mixed> $piece */
	public function add(array $piece, int $order = 0): void
	{
		$validatedPiece = [];
		foreach ($piece as $key => $value) {
			if (!is_string($key)) {
				throw new \InvalidArgumentException('JSON object pieces must use string keys.');
			}
			$validatedPiece[$key] = $value;
		}

		$this->pieces[] = ['piece' => $validatedPiece, 'order' => $order, 'sequence' => $this->sequence++];
	}

	/** @return array<string,mixed> */
	public function compose(): array
	{
		$pieces = $this->pieces;
		usort($pieces, static fn (array $left, array $right): int =>
			$left['order'] <=> $right['order'] ?: $left['sequence'] <=> $right['sequence']);

		$result = [];
		foreach ($pieces as $entry) {
			foreach ($entry['piece'] as $key => $value) {
				if (array_key_exists($key, $result)) {
					throw new LogicException(sprintf('JSON object key "%s" was contributed more than once.', $key));
				}
				$result[$key] = $value;
			}
		}

		return $result;
	}
}
