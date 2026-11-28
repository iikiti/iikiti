<?php

declare(strict_types=1);

namespace iikiti\CMS\Exception;

final class AssetInUseException extends \RuntimeException
{
	private readonly int $assetId;

	/**
	 * @param list<array<string, mixed>> $usages
	 */
	public function __construct(
		int|string $assetId,
		private readonly array $usages,
		?string $message = null,
		int $code = 0,
		?\Throwable $previous = null,
	) {
		$this->assetId = (int) $assetId;
		$resolvedMessage = $message ?? sprintf(
			'Digital asset #%d is currently referenced in %d location(s) and cannot be deleted without force=1.',
			$this->assetId,
			count($this->usages),
		);

		parent::__construct($resolvedMessage, $code, $previous);
	}

	public function getAssetId(): int
	{
		return $this->assetId;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getUsages(): array
	{
		return $this->usages;
	}

	public function getUsageCount(): int
	{
		return count($this->usages);
	}
}
