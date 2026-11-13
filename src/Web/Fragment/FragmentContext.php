<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Fragment;

use iikiti\CMS\Entity\Object\Site;

/** Site-scoped, validated inputs for one public fragment render. */
final readonly class FragmentContext
{
	/**
	 * @param array<array-key,mixed> $params
	 */
	public function __construct(
		public ?Site $site,
		public string $locale,
		public array $params = [],
	) {
		if (strlen($locale) > 64 || 1 === preg_match('/[\x00-\x1F\x7F]/', $locale)) {
			throw new \InvalidArgumentException('Invalid fragment locale.');
		}
		if ([] !== $params && array_is_list($params)) {
			throw new \InvalidArgumentException('Fragment parameters must be a JSON object.');
		}
		if (16_384 < strlen(json_encode($params, JSON_THROW_ON_ERROR, 16))) {
			throw new \InvalidArgumentException('Fragment parameters exceed the request size limit.');
		}
	}
}
