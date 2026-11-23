<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Render;

/**
 * Validation failure returned by {@see BlockTreeValidatorInterface}.
 */
final class BlockTreeViolation
{
	public function __construct(
		public readonly string $region,
		public readonly int $index,
		public readonly string $type,
		public readonly string $message,
	) {
	}

	/**
	 * @return array{region: string, index: int, type: string}
	 */
	public function toArray(): array
	{
		return [
			'region' => $this->region,
			'index' => $this->index,
			'type' => $this->type,
		];
	}
}
