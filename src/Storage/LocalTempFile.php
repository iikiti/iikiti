<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Represents a locally readable file materialized from an {@see AssetStorageInterface}
 * backend. Calling {@see cleanup()} removes temporary copies while leaving original
 * local storage files untouched.
 */
#[Exclude]
final readonly class LocalTempFile
{
	public function __construct(
		public string $path,
		public bool $isTemporaryCopy,
	) {
	}

	public function cleanup(): void
	{
		if ($this->isTemporaryCopy && is_file($this->path)) {
			@unlink($this->path);
		}
	}
}
