<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Service;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Extracts normalized [0.0..1.0] peak amplitude buckets from PCM 16-bit signed
 * little-endian mono audio streams.
 */
final readonly class WaveformPeakExtractor
{
	public const DEFAULT_BUCKETS = 120;
	public const MAX_BUCKETS = 10000;
	public const MAX_DURATION_SECONDS = 7200;
	private const SAMPLE_RATE_HZ = 8000;
	private const DEFAULT_TIMEOUT_SECONDS = 120;
	private const MAX_PCM_BYTES = self::SAMPLE_RATE_HZ * 2 * self::MAX_DURATION_SECONDS;
	private const EXACT_BUFFER_LIMIT_BYTES = 262144;
	private const BLOCK_SAMPLES = 64;

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
	 * Reduces pre-aggregated block maximum amplitudes into normalized [0.0, 1.0] buckets.
	 *
	 * @param list<int> $blockAmplitudes
	 *
	 * @return list<float>
	 */
	public function reduceBlockAmplitudes(array $blockAmplitudes, int $buckets = self::DEFAULT_BUCKETS): array
	{
		if ($buckets < 1 || $buckets > self::MAX_BUCKETS) {
			throw new \InvalidArgumentException(sprintf('Waveform bucket count must be between 1 and %d.', self::MAX_BUCKETS));
		}

		$count = count($blockAmplitudes);
		if (0 === $count) {
			return [];
		}

		$peaks = [];
		for ($bucket = 0; $bucket < $buckets; ++$bucket) {
			$start = (int) floor(($bucket * $count) / $buckets);
			$end = (int) floor((($bucket + 1) * $count) / $buckets);
			if ($end <= $start) {
				$end = min($count, $start + 1);
			}
			if ($start >= $count) {
				$start = $count - 1;
				$end = $count;
			}

			$maxAmplitude = 0;
			for ($i = $start; $i < $end; ++$i) {
				if ($blockAmplitudes[$i] > $maxAmplitude) {
					$maxAmplitude = $blockAmplitudes[$i];
					if (32768 === $maxAmplitude) {
						break;
					}
				}
			}

			$peaks[] = round(min(1.0, max(0.0, $maxAmplitude / 32768.0)), 4);
		}

		return $peaks;
	}

	/**
	 * Decodes an audio or video file to mono 16-bit PCM via FFmpeg using bounded
	 * streaming stdout consumption (capped at {@see self::MAX_DURATION_SECONDS})
	 * and computes normalized waveform peak buckets.
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
			'-t', (string) self::MAX_DURATION_SECONDS,
			'-vn',
			'-ac', '1',
			'-ar', (string) self::SAMPLE_RATE_HZ,
			'-f', 's16le',
			'-acodec', 'pcm_s16le',
			'pipe:1',
		]);
		$process->setTimeout(self::DEFAULT_TIMEOUT_SECONDS);

		$streamingMode = false;
		$exactBuffer = '';
		$carryByte = '';
		$bytesProcessed = 0;
		/** @var list<int> $blockAmplitudes */
		$blockAmplitudes = [];
		$currentBlockMax = 0;
		$currentBlockSamples = 0;
		$maxStoredBlocks = max(4096, $buckets * 8);

		$ingestPcm = static function (string $bytes) use (
			&$carryByte,
			&$blockAmplitudes,
			&$currentBlockMax,
			&$currentBlockSamples,
			$maxStoredBlocks,
		): void {
			$data = $carryByte.$bytes;
			$len = strlen($data);
			if ($len < 2) {
				$carryByte = $data;

				return;
			}
			if (1 === ($len % 2)) {
				$carryByte = substr($data, -1);
				$data = substr($data, 0, -1);
			} else {
				$carryByte = '';
			}

			$unpacked = unpack('v*', $data);
			if (false === $unpacked) {
				return;
			}

			foreach ($unpacked as $unsigned) {
				$value = (int) $unsigned;
				$signed = $value >= 32768 ? $value - 65536 : $value;
				$amplitude = abs($signed);
				if ($amplitude > $currentBlockMax) {
					$currentBlockMax = $amplitude;
				}
				++$currentBlockSamples;
				if ($currentBlockSamples >= self::BLOCK_SAMPLES) {
					$blockAmplitudes[] = $currentBlockMax;
					$currentBlockMax = 0;
					$currentBlockSamples = 0;
					if (count($blockAmplitudes) >= $maxStoredBlocks * 2) {
						$coalesced = [];
						$total = count($blockAmplitudes);
						for ($i = 0; $i < $total; $i += 2) {
							$coalesced[] = isset($blockAmplitudes[$i + 1])
								? max($blockAmplitudes[$i], $blockAmplitudes[$i + 1])
								: $blockAmplitudes[$i];
						}
						$blockAmplitudes = $coalesced;
					}
				}
			}
		};

		$process->run(static function (string $type, string $chunk) use (
			$process,
			&$streamingMode,
			&$exactBuffer,
			&$bytesProcessed,
			$ingestPcm,
		): void {
			if (Process::OUT !== $type) {
				return;
			}

			$process->clearOutput();

			if ('' === $chunk || $bytesProcessed >= self::MAX_PCM_BYTES) {
				return;
			}

			$remaining = self::MAX_PCM_BYTES - $bytesProcessed;
			if (strlen($chunk) > $remaining) {
				$chunk = substr($chunk, 0, $remaining);
			}
			$bytesProcessed += strlen($chunk);

			if (!$streamingMode) {
				if (strlen($exactBuffer) + strlen($chunk) <= self::EXACT_BUFFER_LIMIT_BYTES) {
					$exactBuffer .= $chunk;

					return;
				}
				$streamingMode = true;
				$ingestPcm($exactBuffer);
				$exactBuffer = '';
			}

			$ingestPcm($chunk);
		});

		if (!$process->isSuccessful()) {
			throw new ProcessFailedException($process);
		}

		if (!$streamingMode) {
			return $this->computePeaks($exactBuffer, $buckets);
		}

		if ($currentBlockSamples > 0) {
			$blockAmplitudes[] = $currentBlockMax;
		}

		return $this->reduceBlockAmplitudes($blockAmplitudes, $buckets);
	}
}
