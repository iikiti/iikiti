<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\AssetWorkflowDefinition;
use iikiti\CMS\Storage\AssetStorageInterface;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Value\LocalizedValue;

/**
 * Extracts intrinsic dimensions (respecting EXIF orientation), privacy-safe EXIF,
 * IPTC, and XMP metadata, dominant color, and BlurHash placeholders from uploaded images.
 */
final class ImageMetadataProcessor implements DigitalAssetProcessorInterface
{
	private const MAX_XMP_SCAN_BYTES = 1048576;
	private const MAX_STRING_LENGTH = 2000;

	public function __construct(
		private readonly AssetStorageInterface $storage,
		private readonly EntityManagerInterface $entityManager,
		private readonly BlurHashEncoder $blurHashEncoder = new BlurHashEncoder(),
	) {
	}

	public function getKey(): string
	{
		return 'image_metadata';
	}

	public function supportsMimePattern(string $mimePattern): bool
	{
		return 'image/*' === $mimePattern;
	}

	public function process(AbstractDigitalAsset $asset, AssetWorkflowDefinition $workflow): void
	{
		$sourceKey = $asset->getStorageKey();
		if (null === $sourceKey) {
			throw new \RuntimeException('Uploaded image has no stored file.');
		}

		$extension = match ($asset->getMimeType()) {
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			default => pathinfo($sourceKey, PATHINFO_EXTENSION) ?: null,
		};

		$preserveGps = true === ($workflow->getOptions()['preserve_gps'] ?? false);

		$localSource = $this->storage->materializeToLocalTemp($sourceKey, $extension);
		try {
			$source = $localSource->path;
			$extracted = $this->extractFromFile($source, $preserveGps);

			if (null !== $extracted['width'] && null !== $extracted['height']) {
				$asset->setWidth($extracted['width']);
				$asset->setHeight($extracted['height']);
			}

			$visual = $this->blurHashEncoder->encodeFromImageFile($source);
			if (null !== $visual) {
				$asset->setDominantColor($visual['dominantColor']);
				$asset->setBlurhash($visual['blurhash']);
			}

			if (null === $asset->getDefaultAlt() && null !== $extracted['suggestedAlt']) {
				$asset->setDefaultAlt($extracted['suggestedAlt']);
			}
			if (null === $asset->getDefaultCaption() && null !== $extracted['suggestedCaption']) {
				$asset->setDefaultCaption($extracted['suggestedCaption']);
			}

			$asset->setMediaMetadata([] !== $extracted['metadata'] ? $extracted['metadata'] : null);
			$this->entityManager->flush();
		} finally {
			$localSource->cleanup();
		}
	}

	/**
	 * Extracts intrinsic dimensions, EXIF/IPTC/XMP metadata, and suggested alt/caption text from a local file.
	 *
	 * @return array{
	 *     width: ?int,
	 *     height: ?int,
	 *     orientation: ?int,
	 *     metadata: array<string,mixed>,
	 *     suggestedAlt: LocalizedValue|string|null,
	 *     suggestedCaption: LocalizedValue|string|null,
	 * }
	 */
	public function extractFromFile(string $path, bool $preserveGps = false): array
	{
		$imageInfo = [];
		$dimensions = @getimagesize($path, $imageInfo);
		$width = is_array($dimensions) && isset($dimensions[0]) && (int) $dimensions[0] > 0 ? (int) $dimensions[0] : null;
		$height = is_array($dimensions) && isset($dimensions[1]) && (int) $dimensions[1] > 0 ? (int) $dimensions[1] : null;

		[$exif, $orientation] = $this->extractExif($path, $preserveGps);
		if (null !== $width && null !== $height && null !== $orientation && in_array($orientation, [5, 6, 7, 8], true)) {
			[$width, $height] = [$height, $width];
		}

		$iptc = $this->extractIptc($imageInfo);
		[$xmp, $xmpLocalized] = $this->extractXmp($path);

		$metadata = [];
		if (null !== $orientation) {
			$metadata['orientation'] = $orientation;
		}
		if ([] !== $exif) {
			$metadata['exif'] = $exif;
		}
		if ([] !== $iptc) {
			$metadata['iptc'] = $iptc;
		}
		if ([] !== $xmp) {
			$metadata['xmp'] = $xmp;
		}

		$suggestedAlt = $this->firstNonEmptyCandidate([
			$xmpLocalized['description'] ?? null,
			$xmp['description'] ?? null,
			$iptc['caption'] ?? null,
			$xmp['headline'] ?? null,
			$iptc['headline'] ?? null,
			$exif['imageDescription'] ?? null,
		]);

		$suggestedCaption = $this->firstNonEmptyCandidate([
			$xmp['headline'] ?? null,
			$iptc['headline'] ?? null,
			$xmpLocalized['description'] ?? null,
			$xmp['description'] ?? null,
			$iptc['caption'] ?? null,
		]);

		return [
			'width' => $width,
			'height' => $height,
			'orientation' => $orientation,
			'metadata' => $metadata,
			'suggestedAlt' => $suggestedAlt,
			'suggestedCaption' => $suggestedCaption,
		];
	}

	/**
	 * @return array{0: array<string,mixed>, 1: ?int}
	 */
	private function extractExif(string $path, bool $preserveGps): array
	{
		if (!function_exists('exif_read_data')) {
			return [[], null];
		}

		$raw = @exif_read_data($path, null, true);
		if (!is_array($raw)) {
			return [[], null];
		}

		$orientation = null;
		$ifd0 = is_array($raw['IFD0'] ?? null) ? $raw['IFD0'] : [];
		$exifSection = is_array($raw['EXIF'] ?? null) ? $raw['EXIF'] : [];

		$rawOrientation = $ifd0['Orientation'] ?? $raw['Orientation'] ?? null;
		if (is_int($rawOrientation) || (is_string($rawOrientation) && ctype_digit($rawOrientation))) {
			$parsedOrientation = (int) $rawOrientation;
			if ($parsedOrientation >= 1 && $parsedOrientation <= 8) {
				$orientation = $parsedOrientation;
			}
		}

		$mapped = [];
		$fieldSources = [
			'make' => $ifd0['Make'] ?? null,
			'model' => $ifd0['Model'] ?? null,
			'software' => $ifd0['Software'] ?? null,
			'artist' => $ifd0['Artist'] ?? null,
			'copyright' => $ifd0['Copyright'] ?? null,
			'imageDescription' => $ifd0['ImageDescription'] ?? null,
			'dateTimeOriginal' => $exifSection['DateTimeOriginal'] ?? $exifSection['DateTimeDigitized'] ?? $ifd0['DateTime'] ?? null,
			'exposureTime' => $exifSection['ExposureTime'] ?? null,
			'fNumber' => $exifSection['FNumber'] ?? null,
			'iso' => $exifSection['ISOSpeedRatings'] ?? $exifSection['PhotographicSensitivity'] ?? null,
			'focalLength' => $exifSection['FocalLength'] ?? null,
			'lensModel' => $exifSection['UndefinedTag:0xA434'] ?? $exifSection['LensModel'] ?? null,
		];

		foreach ($fieldSources as $key => $value) {
			if (is_array($value)) {
				$value = $value[0] ?? null;
			}
			if (is_int($value) || is_float($value)) {
				$mapped[$key] = $value;
			} elseif (is_string($value)) {
				$sanitized = $this->sanitizeString($value);
				if (null !== $sanitized) {
					$mapped[$key] = $sanitized;
				}
			}
		}

		if (null !== $orientation) {
			$mapped['orientation'] = $orientation;
		}

		if ($preserveGps) {
			$gps = $this->extractGpsSection($raw);
			if ([] !== $gps) {
				$mapped['gps'] = $gps;
			}
		}

		return [$mapped, $orientation];
	}

	/**
	 * @param array<string,mixed> $rawExif
	 *
	 * @return array<string,mixed>
	 */
	private function extractGpsSection(array $rawExif): array
	{
		$gpsRaw = [];
		foreach ($rawExif as $sectionName => $sectionData) {
			if (0 === strcasecmp($sectionName, 'GPS') && is_array($sectionData)) {
				$gpsRaw = array_merge($gpsRaw, $sectionData);
				continue;
			}
			if (is_array($sectionData)) {
				foreach ($sectionData as $tag => $value) {
					if (is_string($tag) && str_starts_with(strtoupper($tag), 'GPS')) {
						$gpsRaw[$tag] = $value;
					}
				}
			} elseif (str_starts_with(strtoupper($sectionName), 'GPS')) {
				$gpsRaw[$sectionName] = $sectionData;
			}
		}

		if ([] === $gpsRaw) {
			return [];
		}

		$gps = [];
		foreach ($gpsRaw as $tag => $value) {
			if (!is_string($tag)) {
				continue;
			}
			$clean = $this->sanitizeExifValue($value);
			if (null !== $clean) {
				$gps[$tag] = $clean;
			}
		}

		return $gps;
	}

	/**
	 * @param array<string,mixed> $imageInfo
	 *
	 * @return array<string,mixed>
	 */
	private function extractIptc(array $imageInfo): array
	{
		if (!function_exists('iptcparse') || !isset($imageInfo['APP13']) || !is_string($imageInfo['APP13'])) {
			return [];
		}

		$parsed = @iptcparse($imageInfo['APP13']);
		if (!is_array($parsed)) {
			return [];
		}

		$iptc = [];
		$singleFields = [
			'2#005' => 'title',
			'2#080' => 'creator',
			'2#085' => 'creatorTitle',
			'2#090' => 'city',
			'2#095' => 'provinceState',
			'2#101' => 'country',
			'2#105' => 'headline',
			'2#110' => 'credit',
			'2#115' => 'source',
			'2#116' => 'copyright',
			'2#120' => 'caption',
		];

		foreach ($singleFields as $tag => $key) {
			$values = $parsed[$tag] ?? null;
			if (!is_array($values) || !isset($values[0]) || !is_string($values[0])) {
				continue;
			}
			$sanitized = $this->sanitizeString($values[0]);
			if (null !== $sanitized) {
				$iptc[$key] = $sanitized;
			}
		}

		if (isset($parsed['2#025']) && is_array($parsed['2#025'])) {
			$keywords = [];
			foreach ($parsed['2#025'] as $keyword) {
				if (!is_string($keyword)) {
					continue;
				}
				$sanitized = $this->sanitizeString($keyword);
				if (null !== $sanitized) {
					$keywords[] = $sanitized;
				}
			}
			if ([] !== $keywords) {
				$iptc['keywords'] = array_values(array_unique($keywords));
			}
		}

		return $iptc;
	}

	/**
	 * Safely extracts allowlisted XMP Dublin Core and Photoshop metadata fields
	 * without network access or external XML entity expansion.
	 *
	 * @return array{0: array<string,mixed>, 1: array<string, LocalizedValue|string>}
	 */
	private function extractXmp(string $path): array
	{
		$handle = @fopen($path, 'rb');
		if (false === $handle) {
			return [[], []];
		}

		try {
			$bytes = fread($handle, self::MAX_XMP_SCAN_BYTES);
		} finally {
			fclose($handle);
		}

		if (false === $bytes || '' === $bytes) {
			return [[], []];
		}

		$xml = $this->sliceXmpPacket($bytes);
		if (null === $xml) {
			return [[], []];
		}

		// Defensively reject DOCTYPE/ENTITY declarations to prevent XXE/billion-laughs.
		if (1 === preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
			return [[], []];
		}

		$previousErrors = libxml_use_internal_errors(true);
		try {
			$dom = new \DOMDocument();
			if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
				return [[], []];
			}

			$xpath = new \DOMXPath($dom);
			$xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
			$xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
			$xpath->registerNamespace('photoshop', 'http://ns.adobe.com/photoshop/1.0/');

			$xmp = [];
			$localized = [];

			foreach (['description' => 'dc:description', 'title' => 'dc:title', 'rights' => 'dc:rights'] as $key => $qname) {
				[$plain, $localizedValue] = $this->extractXmpAltText($xpath, $qname);
				if (null !== $plain) {
					$xmp[$key] = $plain;
				}
				if (null !== $localizedValue) {
					$localized[$key] = $localizedValue;
				}
			}

			$headline = $this->extractXmpSimpleText($xpath, 'photoshop:Headline');
			if (null !== $headline) {
				$xmp['headline'] = $headline;
			}

			$creators = $this->extractXmpList($xpath, 'dc:creator');
			if ([] !== $creators) {
				$xmp['creator'] = 1 === count($creators) ? $creators[0] : $creators;
			}

			$subjects = $this->extractXmpList($xpath, 'dc:subject');
			if ([] !== $subjects) {
				$xmp['subject'] = $subjects;
			}

			return [$xmp, $localized];
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previousErrors);
		}
	}

	private function sliceXmpPacket(string $bytes): ?string
	{
		if (1 === preg_match('#<x:xmpmeta\b[^>]*>.*?</x:xmpmeta>#is', $bytes, $matches)) {
			return $matches[0];
		}
		if (1 === preg_match('#<rdf:RDF\b[^>]*>.*?</rdf:RDF>#is', $bytes, $matches)) {
			return $matches[0];
		}

		return null;
	}

	/**
	 * Extracts an `rdf:Alt` (or simple text/attribute) XMP element, returning both a plain
	 * string summary and a `LocalizedValue` when multiple valid BCP 47 locales are present.
	 *
	 * @return array{0: ?string, 1: LocalizedValue|string|null}
	 */
	private function extractXmpAltText(\DOMXPath $xpath, string $qname): array
	{
		$items = $xpath->query(sprintf('//%s/rdf:Alt/rdf:li', $qname));
		if ($items instanceof \DOMNodeList && $items->length > 0) {
			$defaultText = null;
			$firstText = null;
			$translations = [];

			foreach ($items as $item) {
				if (!$item instanceof \DOMElement) {
					continue;
				}
				$text = $this->sanitizeString($item->textContent);
				if (null === $text) {
					continue;
				}
				$firstText ??= $text;
				$lang = trim($item->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang'));
				if ('' === $lang) {
					$lang = trim($item->getAttribute('xml:lang'));
				}
				if ('x-default' === strtolower($lang) || '' === $lang) {
					$defaultText ??= $text;
					continue;
				}
				try {
					$tag = (string) LocaleTag::fromString($lang);
					$translations[$tag] = $text;
				} catch (\InvalidArgumentException) {
					// Ignore non-BCP 47 xml:lang values in XMP.
				}
			}

			$plain = $defaultText ?? $firstText;
			if (count($translations) >= 2) {
				return [$plain, LocalizedValue::fromTranslations($translations)];
			}

			return [$plain, $plain];
		}

		$plain = $this->extractXmpSimpleText($xpath, $qname);

		return [$plain, $plain];
	}

	private function extractXmpSimpleText(\DOMXPath $xpath, string $qname): ?string
	{
		$nodes = $xpath->query(sprintf('//%s', $qname));
		if ($nodes instanceof \DOMNodeList) {
			foreach ($nodes as $node) {
				$sanitized = $this->sanitizeString($node->textContent);
				if (null !== $sanitized) {
					return $sanitized;
				}
			}
		}

		$attrNodes = $xpath->query(sprintf('//rdf:Description/@%s', $qname));
		if ($attrNodes instanceof \DOMNodeList) {
			foreach ($attrNodes as $attr) {
				$sanitized = $this->sanitizeString((string) $attr->nodeValue);
				if (null !== $sanitized) {
					return $sanitized;
				}
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private function extractXmpList(\DOMXPath $xpath, string $qname): array
	{
		$results = [];
		$nodes = $xpath->query(sprintf('//%s//rdf:li', $qname));
		if ($nodes instanceof \DOMNodeList && $nodes->length > 0) {
			foreach ($nodes as $node) {
				$sanitized = $this->sanitizeString($node->textContent);
				if (null !== $sanitized) {
					$results[] = $sanitized;
				}
			}
		} else {
			$single = $this->extractXmpSimpleText($xpath, $qname);
			if (null !== $single) {
				$results[] = $single;
			}
		}

		return array_values(array_unique($results));
	}

	/**
	 * @param list<mixed> $candidates
	 */
	private function firstNonEmptyCandidate(array $candidates): LocalizedValue|string|null
	{
		foreach ($candidates as $candidate) {
			if ($candidate instanceof LocalizedValue) {
				return $candidate;
			}
			if (is_string($candidate) && '' !== trim($candidate)) {
				return trim($candidate);
			}
		}

		return null;
	}

	private function sanitizeExifValue(mixed $value): mixed
	{
		if (is_int($value) || is_float($value) || is_bool($value)) {
			return $value;
		}
		if (is_string($value)) {
			return $this->sanitizeString($value);
		}
		if (is_array($value)) {
			$result = [];
			foreach ($value as $k => $v) {
				$clean = $this->sanitizeExifValue($v);
				if (null !== $clean) {
					$result[$k] = $clean;
				}
			}

			return [] !== $result ? $result : null;
		}

		return null;
	}

	private function sanitizeString(string $value): ?string
	{
		if (!mb_check_encoding($value, 'UTF-8')) {
			$converted = @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
			$value = is_string($converted) ? $converted : '';
		}
		$cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
		$cleaned = trim($cleaned);
		if ('' === $cleaned) {
			return null;
		}

		return mb_substr($cleaned, 0, self::MAX_STRING_LENGTH);
	}
}
