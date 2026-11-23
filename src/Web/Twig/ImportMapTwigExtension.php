<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the build-generated ESM import map (`public/build/esm/importmap.json`)
 * and hashed ESM entry URLs to Twig templates so core and plugin ES module
 * bundles share one physical Svelte 5 runtime instance.
 */
final class ImportMapTwigExtension extends AbstractExtension
{
	/** @var array{imports?:array<string,string>,entries?:array<string,string>}|null */
	private ?array $cachedManifest = null;

	public function __construct(
		#[Autowire('%kernel.project_dir%/public/build/esm/importmap.json')]
		private readonly string $manifestPath,
	) {
	}

	/** @return list<TwigFunction> */
	public function getFunctions(): array
	{
		return [
			new TwigFunction('iikiti_import_map_json', $this->getImportMapJson(...), ['is_safe' => ['html']]),
			new TwigFunction('iikiti_esm_entry', $this->getEntryUrl(...)),
		];
	}

	public function getImportMapJson(): string
	{
		$manifest = $this->readManifest();
		$imports = is_array($manifest['imports'] ?? null) ? $manifest['imports'] : [];

		return json_encode(
			['imports' => [] === $imports ? new \stdClass() : $imports],
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
		);
	}

	public function getEntryUrl(string $name): string
	{
		$manifest = $this->readManifest();
		$entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
		$url = $entries[$name] ?? null;

		return is_string($url) && '' !== $url ? $url : '/build/esm/'.rawurlencode($name).'.mjs';
	}

	/** @return array{imports?:array<string,string>,entries?:array<string,string>} */
	private function readManifest(): array
	{
		if (null !== $this->cachedManifest) {
			return $this->cachedManifest;
		}
		if (!is_file($this->manifestPath)) {
			return $this->cachedManifest = [];
		}
		$raw = file_get_contents($this->manifestPath);
		if (false === $raw) {
			return $this->cachedManifest = [];
		}
		$decoded = json_decode($raw, true);

		return $this->cachedManifest = is_array($decoded) ? $decoded : [];
	}
}
