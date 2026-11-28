<?php

declare(strict_types=1);

namespace iikiti\Extension\MediaAv\Service;

/**
 * Pure parser transforming `ffprobe -v quiet -print_format json -show_format -show_streams`
 * output into a normalized media metadata structure.
 */
final readonly class FfprobeMetadataParser
{
	/**
	 * @return array{
	 *     durationMs: ?int,
	 *     width: ?int,
	 *     height: ?int,
	 *     bitrate: ?int,
	 *     videoCodec: ?string,
	 *     audioCodec: ?string,
	 *     frameRate: ?float,
	 *     sampleRate: ?int,
	 *     channels: ?int,
	 *     hasVideo: bool,
	 *     hasAudio: bool,
	 * }
	 */
	public function parse(string $json): array
	{
		try {
			$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new \InvalidArgumentException('Invalid ffprobe JSON output.', 0, $exception);
		}

		if (!is_array($decoded)) {
			throw new \InvalidArgumentException('ffprobe JSON output must decode to an object.');
		}

		return $this->parseArray($decoded);
	}

	/**
	 * @param array<mixed> $data
	 *
	 * @return array{
	 *     durationMs: ?int,
	 *     width: ?int,
	 *     height: ?int,
	 *     bitrate: ?int,
	 *     videoCodec: ?string,
	 *     audioCodec: ?string,
	 *     frameRate: ?float,
	 *     sampleRate: ?int,
	 *     channels: ?int,
	 *     hasVideo: bool,
	 *     hasAudio: bool,
	 * }
	 */
	public function parseArray(array $data): array
	{
		$format = is_array($data['format'] ?? null) ? $data['format'] : [];
		$streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];

		$videoStream = null;
		$audioStream = null;
		$maxStreamDurationSec = null;

		foreach ($streams as $stream) {
			if (!is_array($stream)) {
				continue;
			}

			$streamDuration = $this->parseNonNegativeFloat($stream['duration'] ?? null);
			if (null !== $streamDuration && (null === $maxStreamDurationSec || $streamDuration > $maxStreamDurationSec)) {
				$maxStreamDurationSec = $streamDuration;
			}

			$codecType = is_string($stream['codec_type'] ?? null) ? strtolower(trim($stream['codec_type'])) : '';
			if ('video' === $codecType && null === $videoStream && !$this->isAttachedPicture($stream)) {
				$videoStream = $stream;
			} elseif ('audio' === $codecType && null === $audioStream) {
				$audioStream = $stream;
			}
		}

		$formatDurationSec = $this->parseNonNegativeFloat($format['duration'] ?? null);
		$durationSec = $formatDurationSec ?? $maxStreamDurationSec;
		$durationMs = null !== $durationSec ? (int) round($durationSec * 1000.0) : null;

		$width = null !== $videoStream ? $this->parsePositiveInt($videoStream['width'] ?? null) : null;
		$height = null !== $videoStream ? $this->parsePositiveInt($videoStream['height'] ?? null) : null;
		$videoCodec = null !== $videoStream ? $this->parseCodecName($videoStream['codec_name'] ?? null) : null;
		$frameRate = null !== $videoStream ? $this->parseStreamFrameRate($videoStream) : null;

		$audioCodec = null !== $audioStream ? $this->parseCodecName($audioStream['codec_name'] ?? null) : null;
		$sampleRate = null !== $audioStream ? $this->parsePositiveInt($audioStream['sample_rate'] ?? null) : null;
		$channels = null !== $audioStream ? $this->parsePositiveInt($audioStream['channels'] ?? null) : null;

		$bitrate = $this->parsePositiveInt($format['bit_rate'] ?? null);
		if (null === $bitrate) {
			$videoBitrate = null !== $videoStream ? $this->parsePositiveInt($videoStream['bit_rate'] ?? null) : null;
			$audioBitrate = null !== $audioStream ? $this->parsePositiveInt($audioStream['bit_rate'] ?? null) : null;
			if (null !== $videoBitrate || null !== $audioBitrate) {
				$bitrate = ($videoBitrate ?? 0) + ($audioBitrate ?? 0);
			}
		}

		return [
			'durationMs' => $durationMs,
			'width' => $width,
			'height' => $height,
			'bitrate' => $bitrate,
			'videoCodec' => $videoCodec,
			'audioCodec' => $audioCodec,
			'frameRate' => $frameRate,
			'sampleRate' => $sampleRate,
			'channels' => $channels,
			'hasVideo' => null !== $videoStream,
			'hasAudio' => null !== $audioStream,
		];
	}

	/**
	 * @param array<mixed> $stream
	 */
	private function isAttachedPicture(array $stream): bool
	{
		$disposition = $stream['disposition'] ?? null;
		if (!is_array($disposition)) {
			return false;
		}

		return 1 === (int) ($disposition['attached_pic'] ?? 0);
	}

	/**
	 * @param array<mixed> $stream
	 */
	private function parseStreamFrameRate(array $stream): ?float
	{
		$avg = $this->parseFrameRate($stream['avg_frame_rate'] ?? null);
		if (null !== $avg) {
			return $avg;
		}

		return $this->parseFrameRate($stream['r_frame_rate'] ?? null);
	}

	private function parseFrameRate(mixed $value): ?float
	{
		if (is_int($value) || is_float($value)) {
			$fps = (float) $value;

			return is_finite($fps) && $fps > 0.0 && $fps <= 1000.0 ? round($fps, 3) : null;
		}
		if (!is_string($value)) {
			return null;
		}

		$trimmed = trim($value);
		if ('' === $trimmed || 'n/a' === strtolower($trimmed)) {
			return null;
		}

		if (str_contains($trimmed, '/')) {
			$parts = explode('/', $trimmed, 2);
			if (!is_numeric($parts[0]) || !is_numeric($parts[1])) {
				return null;
			}
			$numerator = (float) $parts[0];
			$denominator = (float) $parts[1];
			if (!is_finite($numerator) || !is_finite($denominator) || $numerator <= 0.0 || $denominator <= 0.0) {
				return null;
			}
			$fps = $numerator / $denominator;

			return is_finite($fps) && $fps > 0.0 && $fps <= 1000.0 ? round($fps, 3) : null;
		}

		if (!is_numeric($trimmed)) {
			return null;
		}
		$fps = (float) $trimmed;

		return is_finite($fps) && $fps > 0.0 && $fps <= 1000.0 ? round($fps, 3) : null;
	}

	private function parseNonNegativeFloat(mixed $value): ?float
	{
		if (is_int($value) || is_float($value)) {
			$float = (float) $value;

			return is_finite($float) && $float >= 0.0 ? $float : null;
		}
		if (!is_string($value)) {
			return null;
		}

		$trimmed = trim($value);
		if ('' === $trimmed || 'n/a' === strtolower($trimmed) || !is_numeric($trimmed)) {
			return null;
		}

		$float = (float) $trimmed;

		return is_finite($float) && $float >= 0.0 ? $float : null;
	}

	private function parsePositiveInt(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value > 0 ? $value : null;
		}
		if (is_string($value)) {
			$trimmed = trim($value);
			if ('' === $trimmed || 'n/a' === strtolower($trimmed) || !ctype_digit($trimmed)) {
				return null;
			}
			$int = (int) $trimmed;

			return $int > 0 ? $int : null;
		}

		return null;
	}

	private function parseCodecName(mixed $value): ?string
	{
		if (!is_string($value)) {
			return null;
		}

		$trimmed = strtolower(trim($value));
		if ('' === $trimmed || 'unknown' === $trimmed || 'n/a' === $trimmed) {
			return null;
		}

		return mb_substr($trimmed, 0, 64);
	}
}
