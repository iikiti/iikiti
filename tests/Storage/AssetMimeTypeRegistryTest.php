<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Storage;

use iikiti\CMS\Storage\AssetMimeTypeDefinition;
use iikiti\CMS\Storage\AssetMimeTypeProviderInterface;
use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\CoreAssetMimeTypeProvider;
use PHPUnit\Framework\TestCase;

final class AssetMimeTypeRegistryTest extends TestCase
{
	public function testCoreProviderRegistersAllTwelveBuiltInTypes(): void
	{
		$registry = new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider()]);

		self::assertCount(12, $registry->getAllowedMimeTypes());
		self::assertTrue($registry->isAllowed('image/jpeg'));
		self::assertTrue($registry->isAllowed('IMAGE/PNG'));
		self::assertTrue($registry->isAllowed('image/avif'));
		self::assertSame('jpg', $registry->extensionFor('image/jpeg'));
		self::assertSame('avif', $registry->extensionFor('image/avif'));
		self::assertSame('pdf', $registry->extensionFor('application/pdf'));
		self::assertSame(26214400, $registry->maxBytesFor('application/pdf'));
		self::assertSame('image', $registry->getCategory('image/webp'));
		self::assertSame('image', $registry->getCategory('image/avif'));
		self::assertSame('document', $registry->getCategory('application/pdf'));
		self::assertSame('other', $registry->getCategory('application/octet-stream'));

		self::assertTrue($registry->isInlineDeliverable('image/jpeg'));
		self::assertTrue($registry->isInlineDeliverable('image/webp'));
		self::assertTrue($registry->isInlineDeliverable('image/avif'));
		self::assertFalse($registry->isInlineDeliverable('application/pdf'));
		self::assertFalse($registry->isInlineDeliverable('text/plain'));
	}

	public function testExplicitlyBlocksDangerousMimeTypesEvenWhenProvidedByPlugins(): void
	{
		$maliciousProvider = new class () implements AssetMimeTypeProviderInterface {
			public function getDefinitions(): array
			{
				return [
					new AssetMimeTypeDefinition('image/svg+xml', 'svg', 'image', 1024, true),
					new AssetMimeTypeDefinition('text/html', 'html', 'document', 1024, true),
					new AssetMimeTypeDefinition('application/xhtml+xml', 'xhtml', 'document', 1024, true),
					new AssetMimeTypeDefinition('application/javascript', 'js', 'script', 1024, true),
					new AssetMimeTypeDefinition('text/javascript', 'js', 'script', 1024, true),
					new AssetMimeTypeDefinition('audio/mpeg', 'mp3', 'audio', 52428800, false),
				];
			}
		};

		$registry = new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider(), $maliciousProvider]);

		foreach (AssetMimeTypeRegistry::BLOCKED_MIME_TYPES as $blocked) {
			self::assertFalse($registry->isAllowed($blocked), $blocked.' must never be allowed');
			self::assertNull($registry->get($blocked));
			self::assertFalse($registry->isInlineDeliverable($blocked));
		}

		self::assertTrue($registry->isAllowed('audio/mpeg'));
		self::assertSame('mp3', $registry->extensionFor('audio/mpeg'));
		self::assertSame(52428800, $registry->maxBytesFor('audio/mpeg'));
		self::assertSame('audio', $registry->getCategory('audio/mpeg'));
	}

	public function testThrowsOnUnsupportedExtensionLookup(): void
	{
		$registry = new AssetMimeTypeRegistry([new CoreAssetMimeTypeProvider()]);

		$this->expectException(\InvalidArgumentException::class);
		$registry->extensionFor('image/svg+xml');
	}
}
