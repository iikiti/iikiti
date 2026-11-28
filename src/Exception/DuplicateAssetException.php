<?php

declare(strict_types=1);

namespace iikiti\CMS\Exception;

use iikiti\CMS\Entity\AbstractDigitalAsset;

final class DuplicateAssetException extends \RuntimeException
{
	/**
	 * @param list<AbstractDigitalAsset> $matches
	 */
	public function __construct(
		private readonly array $matches,
		?string $message = null,
		int $code = 0,
		?\Throwable $previous = null,
	) {
		$resolvedMessage = $message ?? sprintf(
			'A duplicate digital asset with identical content already exists (%d matching asset(s)).',
			count($this->matches),
		);

		parent::__construct($resolvedMessage, $code, $previous);
	}

	/**
	 * @return list<AbstractDigitalAsset>
	 */
	public function getMatches(): array
	{
		return $this->matches;
	}
}
