<?php

namespace iikiti\CMS\Plugin;

/**
 * A verified plugin package that has been downloaded to the local cache.
 */
final readonly class PluginPackage
{
	public function __construct(
		public string $slug,
		public string $version,
		public string $filePath,
		public PluginSource $source,
		public PluginState $state,
		public ?string $checksum = null,
		public ?string $signature = null,
		public ?string $signedBy = null,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toInstallInfoArray(?string $installedAt = null): array
	{
		return [
			'slug' => $this->slug,
			'version' => $this->version,
			'source' => $this->source->value,
			'checksum' => $this->checksum,
			'signature' => $this->signature,
			'signed_by' => $this->signedBy,
			'state' => $this->state->value,
			'installed_at' => $installedAt ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
		];
	}
}
