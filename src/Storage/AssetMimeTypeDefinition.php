<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Immutable definition describing an allowed digital asset MIME type.
 */
#[Exclude]
final readonly class AssetMimeTypeDefinition
{
	public function __construct(
		public string $mimeType,
		public string $extension,
		public string $category,
		public int $maxBytes,
		public bool $inlineDelivery = false,
	) {
		if (1 !== preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~', $mimeType)) {
			throw new \InvalidArgumentException(sprintf('Invalid MIME type definition "%s".', $mimeType));
		}
		if (1 !== preg_match('/^[a-z0-9]{1,12}$/', $extension)) {
			throw new \InvalidArgumentException(sprintf('Invalid file extension "%s".', $extension));
		}
		if ('' === trim($category)) {
			throw new \InvalidArgumentException('MIME type category cannot be empty.');
		}
		if ($maxBytes < 1) {
			throw new \InvalidArgumentException('MIME type maxBytes must be a positive integer.');
		}
	}
}
