<?php

declare(strict_types=1);

namespace iikiti\CMS\Storage;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Aggregates core and plugin-provided {@see AssetMimeTypeDefinition} entries while
 * unconditionally blocking active web script/markup MIME types.
 */
final class AssetMimeTypeRegistry implements ResetInterface
{
	/** @var list<string> */
	public const BLOCKED_MIME_TYPES = [
		'image/svg+xml',
		'text/html',
		'application/xhtml+xml',
		'application/javascript',
		'text/javascript',
	];

	/**
	 * Internal system-generated streaming artifacts (HLS playlists & MPEG-TS segments)
	 * allowed for internal {@see AssetStorageInterface::writeStream()} calls while
	 * remaining disallowed for direct user uploads via {@see self::isAllowed()}.
	 *
	 * @var list<string>
	 */
	public const INTERNAL_STREAM_MIME_TYPES = [
		'application/vnd.apple.mpegurl',
		'video/mp2t',
	];

	/** @var list<AssetMimeTypeProviderInterface> */
	private readonly array $providers;

	/** @var array<string,AssetMimeTypeDefinition>|null */
	private ?array $definitions = null;

	/**
	 * @param iterable<AssetMimeTypeProviderInterface>|null $providers
	 */
	public function __construct(
		#[AutowireIterator('iikiti.asset_mime_type_provider')]
		?iterable $providers = null,
	) {
		$resolvedProviders = [];
		foreach ($providers ?? [new CoreAssetMimeTypeProvider()] as $provider) {
			$resolvedProviders[] = $provider;
		}
		$this->providers = $resolvedProviders;
	}

	public function reset(): void
	{
		$this->definitions = null;
	}

	public function isAllowed(string $mime): bool
	{
		$normalized = $this->normalize($mime);
		if (in_array($normalized, self::BLOCKED_MIME_TYPES, true)) {
			return false;
		}

		$definitions = $this->resolveDefinitions();

		return isset($definitions[$normalized]);
	}

	public function isAllowedForInternalStream(string $mime): bool
	{
		$normalized = $this->normalize($mime);
		if (in_array($normalized, self::BLOCKED_MIME_TYPES, true)) {
			return false;
		}

		if (in_array($normalized, self::INTERNAL_STREAM_MIME_TYPES, true)) {
			return true;
		}

		return $this->isAllowed($normalized);
	}

	public function get(string $mime): ?AssetMimeTypeDefinition
	{
		$normalized = $this->normalize($mime);
		if (in_array($normalized, self::BLOCKED_MIME_TYPES, true)) {
			return null;
		}

		$definitions = $this->resolveDefinitions();

		return $definitions[$normalized] ?? null;
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
		return array_values(array_keys($this->resolveDefinitions()));
	}

	public function getCategory(string $mime): string
	{
		$definition = $this->get($mime);

		return $definition?->category ?? 'other';
	}

	/**
	 * @return array<string,AssetMimeTypeDefinition>
	 */
	private function resolveDefinitions(): array
	{
		if (null !== $this->definitions) {
			return $this->definitions;
		}

		$definitions = [];
		foreach ($this->providers as $provider) {
			foreach ($provider->getDefinitions() as $definition) {
				$mime = $this->normalize($definition->mimeType);
				if (in_array($mime, self::BLOCKED_MIME_TYPES, true)) {
					continue;
				}
				$definitions[$mime] = $definition;
			}
		}

		return $this->definitions = $definitions;
	}

	private function normalize(string $mime): string
	{
		return strtolower(trim($mime));
	}
}
