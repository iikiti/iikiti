<?php

namespace iikiti\CMS\Plugin;

use iikiti\CMS\Plugin\Exception\InvalidManifestException;

/**
 * Parsed representation of a plugin's `plugin.json` manifest.
 *
 * The manifest is the single source of truth for a plugin's identity,
 * namespace, bundle class, version constraints and declared capabilities.
 */
final class PluginManifest
{
	public const FILENAME = 'plugin.json';

	/** The vendor prefix reserved for plugins built exclusively by iikiti. */
	public const RESERVED_VENDOR = 'iikiti';

	public const EDITION_STANDARD = 'standard';
	public const EDITION_PRO = 'pro';

	public function __construct(
		public readonly string $name,
		public readonly string $slug,
		public readonly string $namespace,
		public readonly string $bundleClass,
		public readonly string $version,
		public readonly string $iikitiVersion,
		public readonly string $description = '',
		/** @var list<array<string,string>> */
		public readonly array $authors = [],
		public readonly string $license = '',
		public readonly string $edition = self::EDITION_STANDARD,
		/** @var array<string,string> slug => version constraint */
		public readonly array $dependencies = [],
		/** @var array<string,mixed> */
		public readonly array $capabilities = [],
		/** @var array<string,array<string,mixed>> */
		public readonly array $permissions = [],
	) {
	}

	/**
	 * Build a manifest from a decoded `plugin.json` array.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @throws InvalidManifestException when a required field is missing or malformed
	 */
	public static function fromArray(array $data): self
	{
		foreach (['name', 'slug', 'namespace', 'bundle_class', 'version', 'iikiti_version'] as $required) {
			if (!isset($data[$required]) || !is_string($data[$required]) || '' === trim($data[$required])) {
				throw new InvalidManifestException(sprintf('Plugin manifest is missing required string field "%s".', $required));
			}
		}

		$slug = trim((string) $data['slug']);
		if (1 !== preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
			throw new InvalidManifestException(sprintf('Plugin slug "%s" must be lowercase alphanumeric with dashes.', $slug));
		}

		$namespace = trim((string) $data['namespace'], '\\');
		if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace)) {
			throw new InvalidManifestException(sprintf('Plugin namespace "%s" is not a valid PHP namespace.', $namespace));
		}

		$bundleClass = ltrim((string) $data['bundle_class'], '\\');
		if (!str_starts_with($bundleClass, $namespace.'\\')) {
			throw new InvalidManifestException(sprintf('Bundle class "%s" must live inside the declared namespace "%s".', $bundleClass, $namespace));
		}

		$dependencies = [];
		if (isset($data['dependencies'])) {
			if (!is_array($data['dependencies'])) {
				throw new InvalidManifestException('Plugin "dependencies" must be an object of slug => version constraint.');
			}
			foreach ($data['dependencies'] as $dependencySlug => $constraint) {
				if (!is_string($dependencySlug) || !is_string($constraint)) {
					throw new InvalidManifestException('Plugin dependencies must map string slugs to string version constraints.');
				}
				$dependencies[$dependencySlug] = $constraint;
			}
		}

		$authors = [];
		if (isset($data['authors'])) {
			if (!is_array($data['authors'])) {
				throw new InvalidManifestException('Plugin "authors" must be an array.');
			}
			foreach ($data['authors'] as $author) {
				if (!is_array($author)) {
					throw new InvalidManifestException('Each plugin author must be an object.');
				}
				$authors[] = array_map(static fn ($value): string => (string) $value, $author);
			}
		}

		return new self(
			name: trim($data['name']),
			slug: $slug,
			namespace: $namespace,
			bundleClass: $bundleClass,
			version: trim((string) $data['version']),
			iikitiVersion: trim((string) $data['iikiti_version']),
			description: (string) ($data['description'] ?? ''),
			authors: $authors,
			license: (string) ($data['license'] ?? ''),
			edition: (string) ($data['edition'] ?? self::EDITION_STANDARD),
			dependencies: $dependencies,
			capabilities: is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [],
			permissions: is_array($data['permissions'] ?? null) ? $data['permissions'] : [],
		);
	}

	/**
	 * Read and parse a plugin.json file.
	 *
	 * @throws InvalidManifestException when the file cannot be read or decoded
	 */
	public static function fromFile(string $path): self
	{
		if (!is_file($path)) {
			throw new InvalidManifestException(sprintf('Plugin manifest not found at "%s".', $path));
		}

		$contents = file_get_contents($path);
		if (false === $contents) {
			throw new InvalidManifestException(sprintf('Plugin manifest at "%s" could not be read.', $path));
		}

		try {
			$data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new InvalidManifestException(sprintf('Plugin manifest at "%s" is not valid JSON: %s', $path, $exception->getMessage()), 0, $exception);
		}

		if (!is_array($data)) {
			throw new InvalidManifestException(sprintf('Plugin manifest at "%s" must decode to an object.', $path));
		}

		return self::fromArray($data);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'slug' => $this->slug,
			'namespace' => $this->namespace,
			'bundle_class' => $this->bundleClass,
			'version' => $this->version,
			'iikiti_version' => $this->iikitiVersion,
			'description' => $this->description,
			'authors' => $this->authors,
			'license' => $this->license,
			'edition' => $this->edition,
			'dependencies' => $this->dependencies,
			'capabilities' => $this->capabilities,
			'permissions' => $this->permissions,
		];
	}

	/**
	 * Whether the plugin's PHP namespace uses the reserved iikiti vendor prefix.
	 */
	public function usesReservedVendor(): bool
	{
		$root = strtok($this->namespace, '\\');

		return self::RESERVED_VENDOR === $root;
	}
}
