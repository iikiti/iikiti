<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin\MediaAv;

use iikiti\CMS\Storage\AssetMimeTypeRegistry;
use iikiti\CMS\Storage\CoreAssetMimeTypeProvider;
use iikiti\Extension\MediaAv\Service\HlsTranscoder;
use iikiti\Extension\MediaAv\Storage\AvMimeTypeProvider;
use PHPUnit\Framework\TestCase;

final class AvMimeTypeProviderTest extends TestCase
{
	public function testRegistersExpectedUploadableAudioAndVideoMimeTypes(): void
	{
		$provider = new AvMimeTypeProvider();
		$definitions = $provider->getDefinitions();

		self::assertCount(7, $definitions);

		$byMime = [];
		foreach ($definitions as $definition) {
			$byMime[$definition->mimeType] = $definition;
			self::assertSame(AvMimeTypeProvider::DEFAULT_MAX_UPLOAD_BYTES, $definition->maxBytes);
			self::assertTrue($definition->inlineDelivery);
		}

		self::assertSame('mp4', $byMime['video/mp4']->extension);
		self::assertSame('video', $byMime['video/mp4']->category);
		self::assertSame('webm', $byMime['video/webm']->extension);
		self::assertSame('video', $byMime['video/webm']->category);
		self::assertSame('mp3', $byMime['audio/mpeg']->extension);
		self::assertSame('audio', $byMime['audio/mpeg']->category);
		self::assertSame('ogg', $byMime['audio/ogg']->extension);
		self::assertSame('audio', $byMime['audio/ogg']->category);
		self::assertSame('wav', $byMime['audio/wav']->extension);
		self::assertSame('audio', $byMime['audio/wav']->category);
		self::assertSame('m4a', $byMime['audio/mp4']->extension);
		self::assertSame('audio', $byMime['audio/mp4']->category);
		self::assertSame('weba', $byMime['audio/webm']->extension);
		self::assertSame('audio', $byMime['audio/webm']->category);

		// Untrusted HLS manifests and segments must not be user-uploadable.
		self::assertArrayNotHasKey('application/vnd.apple.mpegurl', $byMime);
		self::assertArrayNotHasKey('video/mp2t', $byMime);
	}

	public function testIntegratesWithAssetMimeTypeRegistryAndCustomLimit(): void
	{
		$customLimit = 104857600;
		$registry = new AssetMimeTypeRegistry([
			new CoreAssetMimeTypeProvider(),
			new AvMimeTypeProvider($customLimit),
		]);

		self::assertTrue($registry->isAllowed('video/mp4'));
		self::assertTrue($registry->isAllowed('audio/mpeg'));
		self::assertTrue($registry->isInlineDeliverable('video/webm'));
		self::assertSame($customLimit, $registry->maxBytesFor('video/mp4'));
		self::assertSame('video', $registry->getCategory('video/mp4'));
		self::assertSame('audio', $registry->getCategory('audio/wav'));
		self::assertFalse($registry->isAllowed('application/vnd.apple.mpegurl'));
		self::assertFalse($registry->isAllowed('image/svg+xml'));
	}

	public function testRejectsNonPositiveMaxUploadBytes(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new AvMimeTypeProvider(0);
	}

	public function testHlsSegmentMappingAndPlaylistRewriting(): void
	{
		$token = str_repeat('a', 64);

		self::assertSame('hls_master', HlsTranscoder::variantKeyForSegment('master.m3u8'));
		self::assertSame('hls_720p', HlsTranscoder::variantKeyForSegment('720p.m3u8'));
		self::assertSame('hls_720p_seg_0001', HlsTranscoder::variantKeyForSegment('720p_seg_0001.ts'));
		self::assertNull(HlsTranscoder::variantKeyForSegment('../secret.m3u8'));
		self::assertNull(HlsTranscoder::variantKeyForSegment('bad.mp4'));

		$playlist = "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:6.0,\n720p_seg_0000.ts\n#EXTINF:4.5,\n720p_seg_0001.ts\n#EXT-X-ENDLIST\n";
		$rewritten = HlsTranscoder::rewritePlaylist($playlist, $token);

		self::assertStringContainsString('/assets/'.$token.'/hls/720p_seg_0000.ts', $rewritten);
		self::assertStringContainsString('/assets/'.$token.'/hls/720p_seg_0001.ts', $rewritten);
		self::assertStringContainsString('#EXT-X-ENDLIST', $rewritten);
	}

	public function testRespectsPluginActivationLazilyWithRegistryReset(): void
	{
		$active = false;
		$toggleProvider = new class ($active) implements \iikiti\CMS\Storage\AssetMimeTypeProviderInterface {
			public function __construct(private bool &$active)
			{
			}

			public function getDefinitions(): array
			{
				return $this->active
					? (new AvMimeTypeProvider())->getDefinitions()
					: [];
			}
		};

		$registry = new AssetMimeTypeRegistry([
			new CoreAssetMimeTypeProvider(),
			$toggleProvider,
		]);

		// Lazy evaluation: toggling before first lookup takes effect without reset.
		$active = true;
		self::assertTrue($registry->isAllowed('video/mp4'));
		self::assertFalse($registry->isAllowed('application/vnd.apple.mpegurl'));
		self::assertTrue($registry->isAllowedForInternalStream('application/vnd.apple.mpegurl'));
		self::assertTrue($registry->isAllowedForInternalStream('video/mp2t'));

		// After deactivating and calling reset(), AV MIME types are no longer allowed.
		$active = false;
		$registry->reset();
		self::assertFalse($registry->isAllowed('video/mp4'));
		self::assertTrue($registry->isAllowed('image/png'));
	}
}
