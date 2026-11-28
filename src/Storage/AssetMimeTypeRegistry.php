<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Aggregates core and plugin-provided {@see AssetMimeTypeDefinition} entries while
 * unconditionally blocking active web script/markup MIME types.
 */
final class AssetMimeTypeRegistry
{
	/** @var list<string> */
	public const BLOCKED_MIME_TYPES = [
		'image/svg+xml',
		'text/html',
		'application/xhtml+xml',
		'application/javascript',
		'text/javascript',
	];

	/** @var array<string,AssetMimeTypeDefinition> */
	private array $definitions = [];

	/**
	 * @param iterable<AssetMimeTypeProviderInterface>|null $providers
	 */
	public function __construct(
		#[AutowireIterator('iikiti.asset_mime_type_provider')]
		?iterable $providers = null,
	) {
		$resolvedProviders = $providers ?? [new CoreAssetMimeTypeProvider()];
		foreach ($resolvedProviders as $provider) {
			foreach ($provider->getDefinitions() as $definition) {
				$mime = strtolower(trim($definition->mimeType));
				if (in_array($mime, self::BLOCKED_MIME_TYPES, true)) {
					continue;
				}
				$this->definitions[$mime] = $definition;
			}
		}
	}

	public function isAllowed(string $mime): bool
	{
		$normalized = $this->normalize($mime);
		if (in_array($normalized, self::BLOCKED_MIME_TYPES, true)) {
			return false;
		}

		return isset($this->definitions[$normalized]);
	}

	public function get(string $mime): ?AssetMimeTypeDefinition
	{
		$normalized = $this->normalize($mime);
		if (in_array($normalized, self::BLOCKED_MIME_TYPES, true)) {
			return null;
		}

		return $this->definitions[$normalized] ?? null;
	}

	public function extensionFor(string $mime): string
	{
		$definition = $this->get($mime);
		if (null === $definition) {
			throw new \InvalidArgumentException('Unsupported asset file type.');
		}

		return $definition->extension;
	}

	public function maxBytesFor(string $mime): int
	{
		$definition = $this->get($mime);
		if (null === $definition) {
			throw new \InvalidArgumentException('Unsupported asset file type.');
		}

		return $definition->maxBytes;
	}

	public function isInlineDeliverable(string $mime): bool
	{
		$definition = $this->get($mime);

		return null !== $definition && $definition->inlineDelivery;
	}

	/**
	 * @return list<string>
	 */
	public function getAllowedMimeTypes(): array
	{
		return array_values(array_keys($this->definitions));
	}

	public function getCategory(string $mime): string
	{
		$definition = $this->get($mime);

		return $definition?->category ?? 'other';
	}

	private function normalize(string $mime): string
	{
		return strtolower(trim($mime));
	}
}
