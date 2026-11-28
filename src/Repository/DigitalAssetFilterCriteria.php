<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Symfony\Component\HttpFoundation\Request;

/**
 * Immutable filter, facet, sort, and pagination criteria for DAM queries.
 */
final readonly class DigitalAssetFilterCriteria
{
	public const SORT_NEWEST = 'newest';
	public const SORT_OLDEST = 'oldest';
	public const SORT_TITLE_ASC = 'title_asc';
	public const SORT_TITLE_DESC = 'title_desc';
	public const SORT_SIZE_DESC = 'size_desc';
	public const SORT_SIZE_ASC = 'size_asc';

	/** @var list<string> */
	public const ALLOWED_SORTS = [
		self::SORT_NEWEST,
		self::SORT_OLDEST,
		self::SORT_TITLE_ASC,
		self::SORT_TITLE_DESC,
		self::SORT_SIZE_DESC,
		self::SORT_SIZE_ASC,
	];

	/** @var list<string> */
	public const ALLOWED_MIME_CATEGORIES = [
		'image',
		'video',
		'audio',
		'document',
		'text',
		'data',
		'other',
	];

	/** @var list<string> */
	public const ALLOWED_VISIBILITIES = ['public', 'private'];

	/** @var list<string> */
	public const ALLOWED_PROCESSING_STATUSES = ['pending', 'processing', 'complete', 'failed'];

	/** @var list<string> */
	public const ALLOWED_LICENSE_STATUSES = ['valid', 'expiring_soon', 'expired', 'missing'];

	/**
	 * @param list<int|string> $tagIds
	 */
	public function __construct(
		public ?string $query = null,
		public ?string $mimeCategory = null,
		public ?string $mimeType = null,
		public int|string|null $siteId = null,
		public ?string $visibility = null,
		public ?string $processingStatus = null,
		public int|string|null $folderId = null,
		public bool $unfiledOnly = false,
		public bool $includeSubfolders = false,
		public int|string|null $collectionId = null,
		public array $tagIds = [],
		public ?string $licenseStatus = null,
		public string $sort = self::SORT_NEWEST,
		public int $limit = 25,
		public int $offset = 0,
	) {
	}

	public static function fromRequest(Request $request, int $defaultLimit = 25, int $maxLimit = 100): self
	{
		return self::fromQueryArray($request->query->all(), $defaultLimit, $maxLimit);
	}

	/**
	 * Parses and validates query parameters into a normalized criteria value object.
	 *
	 * @param array<string,mixed> $params
	 */
	public static function fromQueryArray(array $params, int $defaultLimit = 25, int $maxLimit = 100): self
	{
		$query = self::nullableString($params['q'] ?? $params['query'] ?? null);

		$mimeCategory = self::nullableLowerString($params['mimeCategory'] ?? null);
		if (null !== $mimeCategory && !in_array($mimeCategory, self::ALLOWED_MIME_CATEGORIES, true)) {
			throw new \InvalidArgumentException(sprintf('Invalid mimeCategory "%s".', $mimeCategory));
		}

		$mimeType = self::nullableLowerString($params['mimeType'] ?? null);

		$siteId = self::nullablePositiveId($params['siteId'] ?? null, 'siteId');

		$visibility = self::nullableLowerString($params['visibility'] ?? null);
		if (null !== $visibility && !in_array($visibility, self::ALLOWED_VISIBILITIES, true)) {
			throw new \InvalidArgumentException(sprintf('Invalid visibility "%s".', $visibility));
		}

		$processingStatus = self::nullableLowerString($params['processingStatus'] ?? null);
		if (null !== $processingStatus && !in_array($processingStatus, self::ALLOWED_PROCESSING_STATUSES, true)) {
			throw new \InvalidArgumentException(sprintf('Invalid processingStatus "%s".', $processingStatus));
		}

		$unfiledOnly = self::parseBool($params['unfiledOnly'] ?? false);
		$rawFolderId = $params['folderId'] ?? null;
		$folderId = null;
		if (is_string($rawFolderId) && 'unfiled' === strtolower(trim($rawFolderId))) {
			$unfiledOnly = true;
		} else {
			$folderId = self::nullablePositiveId($rawFolderId, 'folderId');
		}
		if ($unfiledOnly) {
			$folderId = null;
		}

		$includeSubfolders = self::parseBool($params['includeSubfolders'] ?? false);
		$collectionId = self::nullablePositiveId($params['collectionId'] ?? null, 'collectionId');
		$tagIds = self::parseIdList($params['tagIds'] ?? ($params['tagId'] ?? null), 'tagIds');

		$licenseStatus = self::nullableLowerString($params['licenseStatus'] ?? null);
		if (null !== $licenseStatus && !in_array($licenseStatus, self::ALLOWED_LICENSE_STATUSES, true)) {
			throw new \InvalidArgumentException(sprintf('Invalid licenseStatus "%s".', $licenseStatus));
		}

		$sort = self::nullableLowerString($params['sort'] ?? null) ?? self::SORT_NEWEST;
		if (!in_array($sort, self::ALLOWED_SORTS, true)) {
			throw new \InvalidArgumentException(sprintf('Invalid sort "%s".', $sort));
		}

		$rawLimit = $params['itemsPerPage'] ?? $params['limit'] ?? $defaultLimit;
		$parsedLimit = filter_var($rawLimit, FILTER_VALIDATE_INT);
		$limit = false === $parsedLimit ? $defaultLimit : min($maxLimit, max(1, $parsedLimit));

		if (array_key_exists('offset', $params) && null !== $params['offset'] && '' !== $params['offset']) {
			$parsedOffset = filter_var($params['offset'], FILTER_VALIDATE_INT);
			$offset = false === $parsedOffset ? 0 : max(0, $parsedOffset);
		} else {
			$rawPage = $params['page'] ?? 1;
			$parsedPage = filter_var($rawPage, FILTER_VALIDATE_INT);
			$page = false === $parsedPage ? 1 : max(1, $parsedPage);
			$offset = ($page - 1) * $limit;
		}

		return new self(
			query: $query,
			mimeCategory: $mimeCategory,
			mimeType: $mimeType,
			siteId: $siteId,
			visibility: $visibility,
			processingStatus: $processingStatus,
			folderId: $folderId,
			unfiledOnly: $unfiledOnly,
			includeSubfolders: $includeSubfolders,
			collectionId: $collectionId,
			tagIds: $tagIds,
			licenseStatus: $licenseStatus,
			sort: $sort,
			limit: $limit,
			offset: $offset,
		);
	}

	/**
	 * Returns a copy with the specified facet dimension cleared, useful for
	 * computing multi-select facet counts without collapsing peer buckets.
	 */
	public function withoutFacet(string $dimension): self
	{
		return new self(
			query: $this->query,
			mimeCategory: 'mimeCategory' === $dimension ? null : $this->mimeCategory,
			mimeType: 'mimeCategory' === $dimension ? null : $this->mimeType,
			siteId: 'siteId' === $dimension ? null : $this->siteId,
			visibility: 'visibility' === $dimension ? null : $this->visibility,
			processingStatus: 'processingStatus' === $dimension ? null : $this->processingStatus,
			folderId: $this->folderId,
			unfiledOnly: $this->unfiledOnly,
			includeSubfolders: $this->includeSubfolders,
			collectionId: $this->collectionId,
			tagIds: $this->tagIds,
			licenseStatus: $this->licenseStatus,
			sort: $this->sort,
			limit: $this->limit,
			offset: $this->offset,
		);
	}

	private static function nullableString(mixed $value): ?string
	{
		if (!is_string($value)) {
			return null;
		}
		$trimmed = trim($value);

		return '' !== $trimmed ? $trimmed : null;
	}

	private static function nullableLowerString(mixed $value): ?string
	{
		$str = self::nullableString($value);

		return null !== $str ? strtolower($str) : null;
	}

	private static function nullablePositiveId(mixed $value, string $fieldName): ?string
	{
		if (null === $value || '' === $value) {
			return null;
		}
		if (is_int($value)) {
			if ($value < 1) {
				throw new \InvalidArgumentException(sprintf('%s must be a positive integer.', $fieldName));
			}

			return (string) $value;
		}
		if (is_string($value)) {
			$trimmed = trim($value);
			if ('' === $trimmed) {
				return null;
			}
			if (!ctype_digit($trimmed) || '0' === $trimmed) {
				throw new \InvalidArgumentException(sprintf('%s must be a positive integer.', $fieldName));
			}

			return $trimmed;
		}

		throw new \InvalidArgumentException(sprintf('%s must be a positive integer.', $fieldName));
	}

	/**
	 * @return list<string>
	 */
	private static function parseIdList(mixed $value, string $fieldName): array
	{
		if (null === $value || '' === $value || [] === $value) {
			return [];
		}

		$rawItems = [];
		if (is_int($value)) {
			$rawItems = [$value];
		} elseif (is_string($value)) {
			$rawItems = explode(',', $value);
		} elseif (is_array($value)) {
			$rawItems = $value;
		} else {
			throw new \InvalidArgumentException(sprintf('%s must be a list of positive integers.', $fieldName));
		}

		$ids = [];
		foreach ($rawItems as $item) {
			$parsed = self::nullablePositiveId($item, $fieldName);
			if (null !== $parsed) {
				$ids[] = $parsed;
			}
		}

		return array_values(array_unique($ids));
	}

	private static function parseBool(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value)) {
			return 0 !== $value;
		}
		if (is_string($value)) {
			return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
		}

		return false;
	}
}
