<?php

declare(strict_types=1);

namespace iikiti\CMS\DigitalAsset\Usage;

/**
 * Immutable representation of a single "Where Used" reference to a digital asset.
 */
final readonly class AssetUsageReference
{
	public function __construct(
		public string $contextType,
		public int $contextId,
		public string $contextTitle,
		public string $fieldPath,
		public bool $isDraft,
		public int $siteId,
		public ?string $editUrl = null,
		public ?string $siteName = null,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'contextType' => $this->contextType,
			'contextId' => $this->contextId,
			'contextTitle' => $this->contextTitle,
			'fieldPath' => $this->fieldPath,
			'isDraft' => $this->isDraft,
			'siteId' => $this->siteId,
			'editUrl' => $this->editUrl,
			// Admin SPA compatibility aliases
			'entityType' => $this->contextType,
			'entityId' => $this->contextId,
			'title' => $this->contextTitle,
			'field' => $this->fieldPath,
			'adminUrl' => $this->editUrl,
			'siteName' => $this->siteName,
		];
	}
}
