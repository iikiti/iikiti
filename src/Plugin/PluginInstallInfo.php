<?php

namespace iikiti\CMS\Plugin;

/**
 * Installation metadata recorded alongside an installed plugin.
 *
 * Stored as `.iikiti-install.json` in the plugin package root. It records where
 * the package came from and the integrity/authenticity data that was verified at
 * install time, so subsequent boots can make trust decisions without contacting
 * the store.
 */
final readonly class PluginInstallInfo
{
	public const FILENAME = '.iikiti-install.json';

	public function __construct(
		public string $slug,
		public string $version,
		public PluginSource $source,
		public ?string $checksum = null,
		public ?string $signature = null,
		public ?string $signedBy = null,
		public PluginState $state = PluginState::PendingReview,
		public ?string $installedAt = null,
	) {
	}

	/**
	 * Treat a plugin directory as a manually installed plugin with no trust
	 * metadata.
	 *
	 * Manual plugins have no verified review state, so they default to
	 * PendingReview: activating them on production (or without the non-approved
	 * override in development) is refused by the state policy.
	 */
	public static function manual(PluginManifest $manifest): self
	{
		return new self(
			slug: $manifest->slug,
			version: $manifest->version,
			source: PluginSource::Manual,
			state: PluginState::PendingReview,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			slug: (string) ($data['slug'] ?? ''),
			version: (string) ($data['version'] ?? ''),
			source: PluginSource::tryFrom((string) ($data['source'] ?? '')) ?? PluginSource::Manual,
			checksum: isset($data['checksum']) ? (string) $data['checksum'] : null,
			signature: isset($data['signature']) ? (string) $data['signature'] : null,
			signedBy: isset($data['signed_by']) ? (string) $data['signed_by'] : null,
			state: PluginState::fromStore(isset($data['state']) ? (string) $data['state'] : null),
			installedAt: isset($data['installed_at']) ? (string) $data['installed_at'] : null,
		);
	}

	/**
	 * Read install metadata from a plugin directory, if present.
	 */
	public static function fromDirectory(string $directory): ?self
	{
		$path = rtrim($directory, '/\\').'/'.self::FILENAME;
		if (!is_file($path)) {
			return null;
		}

		$contents = file_get_contents($path);
		if (false === $contents) {
			return null;
		}

		try {
			$data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return is_array($data) ? self::fromArray($data) : null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'slug' => $this->slug,
			'version' => $this->version,
			'source' => $this->source->value,
			'checksum' => $this->checksum,
			'signature' => $this->signature,
			'signed_by' => $this->signedBy,
			'state' => $this->state->value,
			'installed_at' => $this->installedAt,
		];
	}

	/**
	 * Persist the metadata into a plugin directory.
	 */
	public function writeTo(string $directory): void
	{
		$path = rtrim($directory, '/\\').'/'.self::FILENAME;
		file_put_contents(
			$path,
			json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
		);
	}
}
