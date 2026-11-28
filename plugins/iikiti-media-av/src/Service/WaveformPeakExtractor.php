<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Service;

use Symfony\Component\Process\Process;

/**
 * Extracts normalized [0.0..1.0] peak amplitude buckets from PCM 16-bit signed
 * little-endian mono audio streams.
 */
final readonly class WaveformPeakExtractor
{
	public const DEFAULT_BUCKETS = 120;
	public const MAX_BUCKETS = 10000;
	private const SAMPLE_RATE_HZ = 8000;
	private const DEFAULT_TIMEOUT_SECONDS = 120;

	/**
	 * Converts raw PCM 16-bit signed little-endian mono bytes into normalized
	 * peak amplitude buckets in the range [0.0, 1.0].
	 *
	 * @return list<float>
	 */
	public function computePeaks(string $pcmBytes, int $buckets = self::DEFAULT_BUCKETS): array
	{
		if ($buckets < 1 || $buckets > self::MAX_BUCKETS) {
			throw new \InvalidArgumentException(sprintf('Waveform bucket count must be between 1 and %d.', self::MAX_BUCKETS));
		}

		$sampleCount = intdiv(strlen($pcmBytes), 2);
		if (0 === $sampleCount) {
			return [];
		}

		$peaks = [];
		for ($bucket = 0; $bucket < $buckets; ++$bucket) {
			$start = (int) floor(($bucket * $sampleCount) / $buckets);
			$end = (int) floor((($bucket + 1) * $sampleCount) / $buckets);
			if ($end <= $start) {
				$end = min($sampleCount, $start + 1);
			}
			if ($start >= $sampleCount) {
				$start = $sampleCount - 1;
				$end = $sampleCount;
			}

			$slice = substr($pcmBytes, $start * 2, ($end - $start) * 2);
			$unpacked = unpack('v*', $slice);
			if (false === $unpacked || [] === $unpacked) {
				$peaks[] = 0.0;

				continue;
			}

			$maxAmplitude = 0;
			foreach ($unpacked as $unsigned) {
				$value = (int) $unsigned;
				$signed = $value >= 32768 ? $value - 65536 : $value;
				$amplitude = abs($signed);
				if ($amplitude > $maxAmplitude) {
					$maxAmplitude = $amplitude;
					if (32768 === $maxAmplitude) {
						break;
					}
				}
			}

			$normalized = min(1.0, max(0.0, $maxAmplitude / 32768.0));
			$peaks[] = round($normalized, 4);
		}

		return $peaks;
	}

	/**
	 * Decodes an audio or video file to mono 16-bit PCM via FFmpeg and computes
	 * normalized waveform peak buckets.
	 *
	 * @return list<float>
	 */
	public function extractFromAudioFile(string $path, string $ffmpegBinary = 'ffmpeg', int $buckets = self::DEFAULT_BUCKETS): array
	{
		if ($buckets < 1 || $buckets > self::MAX_BUCKETS) {
			throw new \InvalidArgumentException(sprintf('Waveform bucket count must be between 1 and %d.', self::MAX_BUCKETS));
		}
		if ('' === trim($path) || str_contains($path, "\0") || !is_file($path)) {
			throw new \InvalidArgumentException('Audio source path does not exist.');
		}
		$binary = trim($ffmpegBinary);
		if ('' === $binary || str_contains($binary, "\0")) {
			throw new \InvalidArgumentException('FFmpeg binary path must be non-empty.');
		}

		$process = new Process([
			$binary,
			'-nostdin',
			'-y',
			'-v', 'error',
			'-threads', '1',
			'-i', $path,
			'-vn',
			'-ac', '1',
			'-ar', (string) self::SAMPLE_RATE_HZ,
			'-f', 's16le',
			'-acodec', 'pcm_s16le',
			'pipe:1',
		]);
		$process->setTimeout(self::DEFAULT_TIMEOUT_SECONDS);
		$process->mustRun();

		return $this->computePeaks($process->getOutput(), $buckets);
	}
}
