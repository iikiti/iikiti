<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin\MediaAv;

use iikiti\Extension\MediaAv\Service\FfprobeMetadataParser;
use PHPUnit\Framework\TestCase;

final class FfprobeMetadataParserTest extends TestCase
{
	public function testParsesVideoAndAudioStreams(): void
	{
		$json = json_encode([
			'format' => [
				'duration' => '125.432',
				'bit_rate' => '2450000',
			],
			'streams' => [
				[
					'codec_type' => 'video',
					'codec_name' => 'h264',
					'width' => 1920,
					'height' => 1080,
					'avg_frame_rate' => '30000/1001',
					'r_frame_rate' => '30000/1001',
				],
				[
					'codec_type' => 'audio',
					'codec_name' => 'aac',
					'sample_rate' => '48000',
					'channels' => 2,
				],
			],
		], JSON_THROW_ON_ERROR);

		$result = (new FfprobeMetadataParser())->parse($json);

		self::assertSame(125432, $result['durationMs']);
		self::assertSame(1920, $result['width']);
		self::assertSame(1080, $result['height']);
		self::assertSame(2450000, $result['bitrate']);
		self::assertSame('h264', $result['videoCodec']);
		self::assertSame('aac', $result['audioCodec']);
		self::assertSame(29.97, $result['frameRate']);
		self::assertSame(48000, $result['sampleRate']);
		self::assertSame(2, $result['channels']);
		self::assertTrue($result['hasVideo']);
		self::assertTrue($result['hasAudio']);
	}

	public function testIgnoresAttachedPictureCoverArtOnAudioFiles(): void
	{
		$json = json_encode([
			'format' => [
				'duration' => '210.5',
				'bit_rate' => '192000',
			],
			'streams' => [
				[
					'codec_type' => 'audio',
					'codec_name' => 'mp3',
					'sample_rate' => '44100',
					'channels' => 2,
				],
				[
					'codec_type' => 'video',
					'codec_name' => 'mjpeg',
					'width' => 600,
					'height' => 600,
					'disposition' => [
						'attached_pic' => 1,
					],
				],
			],
		], JSON_THROW_ON_ERROR);

		$result = (new FfprobeMetadataParser())->parse($json);

		self::assertFalse($result['hasVideo']);
		self::assertTrue($result['hasAudio']);
		self::assertNull($result['width']);
		self::assertNull($result['height']);
		self::assertNull($result['videoCodec']);
		self::assertNull($result['frameRate']);
		self::assertSame('mp3', $result['audioCodec']);
		self::assertSame(44100, $result['sampleRate']);
		self::assertSame(2, $result['channels']);
		self::assertSame(210500, $result['durationMs']);
	}

	public function testHandlesFallbacksAndNaValues(): void
	{
		$json = json_encode([
			'format' => [
				'duration' => 'N/A',
				'bit_rate' => 'N/A',
			],
			'streams' => [
				[
					'codec_type' => 'video',
					'codec_name' => 'vp9',
					'width' => 1280,
					'height' => 720,
					'duration' => '42.25',
					'bit_rate' => '1200000',
					'avg_frame_rate' => '0/0',
					'r_frame_rate' => '24/1',
				],
				[
					'codec_type' => 'audio',
					'codec_name' => 'opus',
					'duration' => '42.5',
					'bit_rate' => '128000',
					'sample_rate' => '48000',
					'channels' => 1,
				],
			],
		], JSON_THROW_ON_ERROR);

		$result = (new FfprobeMetadataParser())->parse($json);

		self::assertSame(42500, $result['durationMs']);
		self::assertSame(1328000, $result['bitrate']);
		self::assertSame(24.0, $result['frameRate']);
		self::assertSame('vp9', $result['videoCodec']);
		self::assertSame('opus', $result['audioCodec']);
	}

	public function testRejectsMalformedJson(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		(new FfprobeMetadataParser())->parse('{not-valid-json');
	}
}
