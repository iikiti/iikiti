<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin\MediaAv;

use iikiti\Extension\MediaAv\Service\WaveformPeakExtractor;
use PHPUnit\Framework\TestCase;

final class WaveformPeakExtractorTest extends TestCase
{
	public function testReturnsEmptyListForEmptyPcm(): void
	{
		$extractor = new WaveformPeakExtractor();

		self::assertSame([], $extractor->computePeaks('', 120));
		self::assertSame([], $extractor->computePeaks("\x01", 120));
	}

	public function testComputesNormalizedPeaksAcrossBuckets(): void
	{
		$extractor = new WaveformPeakExtractor();

		// 4 buckets, 2 samples each (signed 16-bit LE):
		// Bucket 0: [0, 0] -> 0.0
		// Bucket 1: [8192, 16384] -> 0.5
		// Bucket 2: [-16384, -32768] -> 1.0
		// Bucket 3: [32767, 0] -> 1.0
		$samples = [0, 0, 8192, 16384, -16384, -32768, 32767, 0];
		$pcm = '';
		foreach ($samples as $sample) {
			$unsigned = $sample < 0 ? $sample + 65536 : $sample;
			$pcm .= pack('v', $unsigned);
		}

		$peaks = $extractor->computePeaks($pcm, 4);

		self::assertCount(4, $peaks);
		self::assertSame(0.0, $peaks[0]);
		self::assertSame(0.5, $peaks[1]);
		self::assertSame(1.0, $peaks[2]);
		self::assertSame(1.0, $peaks[3]);
	}

	public function testHandlesSilenceAndShortSampleStreams(): void
	{
		$extractor = new WaveformPeakExtractor();

		$silence = str_repeat("\x00\x00", 240);
		$silentPeaks = $extractor->computePeaks($silence, 120);
		self::assertCount(120, $silentPeaks);
		self::assertSame(0.0, max($silentPeaks));

		$shortPcm = pack('vv', 16384, 32768);
		$shortPeaks = $extractor->computePeaks($shortPcm, 4);
		self::assertCount(4, $shortPeaks);
		self::assertSame([0.5, 0.5, 1.0, 1.0], $shortPeaks);
	}

	public function testRejectsInvalidBucketCountAndMissingFiles(): void
	{
		$extractor = new WaveformPeakExtractor();

		try {
			$extractor->computePeaks("\x00\x00", 0);
			self::fail('Expected InvalidArgumentException for 0 buckets.');
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}

		$this->expectException(\InvalidArgumentException::class);
		$extractor->extractFromAudioFile('/nonexistent/path/audio.mp3');
	}

	public function testReduceBlockAmplitudesNormalizesAndBoundsPeaks(): void
	{
		$extractor = new WaveformPeakExtractor();

		self::assertSame([], $extractor->reduceBlockAmplitudes([], 4));
		self::assertSame(
			[0.0, 0.5, 1.0, 0.25],
			$extractor->reduceBlockAmplitudes([0, 16384, 32768, 8192], 4),
		);
	}
}
