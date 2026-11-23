<?php

declare(strict_types=1);

namespace iikiti\Extension\Forms\BlockEditor;

final readonly class FormBlockViolation
{
	public function __construct(
		public string $region,
		public int $index,
		public string $type,
		public string $rule,
	) {
	}
}
