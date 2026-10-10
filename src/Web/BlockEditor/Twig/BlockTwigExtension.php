<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Twig;

use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\BlockEditor\Embed\EmbedResolver;
use iikiti\CMS\Web\BlockEditor\Query\QueryDefinition;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use iikiti\CMS\Web\Icon\IconFontAssets;
use iikiti\CMS\Web\Icon\IconResolver;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers for rendering blocks in theme/layout templates:
 * - `iikiti_embed(url, options)`        -> resolved embed HTML (cached, allowlisted)
 * - `iikiti_query(definition)`          -> list of result rows for a query block
 * - `iikiti_region(id, role, name, allowed, html)` -> region wrapper markup
 * - `iikiti_icon(name, renderer, ...)`  -> icon block markup (svg; unknown -> empty)
 */
final class BlockTwigExtension extends AbstractExtension
{
	/** Renderers an icon block may choose. Anything else renders nothing. */
	public const ICON_RENDERERS = ['svg', 'font'];

	public function __construct(
		private readonly EmbedResolver $embedResolver,
		private readonly QueryExecutor $queryExecutor,
		private readonly IconResolver $iconResolver,
		private readonly IconFontAssets $iconFontAssets,
	) {
	}

	public function getFunctions(): array
	{
		return [
			new TwigFunction('iikiti_embed', [$this, 'embed'], ['is_safe' => ['html']]),
			new TwigFunction('iikiti_query', [$this, 'query']),
			new TwigFunction(
				'iikiti_region',
				[$this, 'region'],
				['is_safe' => ['html'], 'needs_environment' => true]
			),
			new TwigFunction('iikiti_icon', [$this, 'icon'], ['is_safe' => ['html']]),
		];
	}

	/**
	 * Icon block output. The name and renderer are validated against the icon set
	 * and an allowlist; an unknown value yields an empty string, never raw input.
	 */
	public function icon(string $name, ?string $renderer = null, ?int $size = null, ?string $color = null, ?float $strokeWidth = null): string
	{
		// Twig passes null for omitted arguments; fall back to the same defaults
		// as IconSet::svg() rather than failing the whole template.
		$renderer ??= 'svg';
		if (!in_array($renderer, self::ICON_RENDERERS, true)) {
			return '';
		}

		$resolved = $this->iconResolver->resolve($name);
		if (null === $resolved) {
			return '';
		}

		if ('font' === $renderer) {
			// Only the bundled set has font glyphs; admin sets fall through to nothing.
			$glyph = $resolved['set']->glyph($resolved['name']);
			if (null === $glyph) {
				return '';
			}
			// The layout links the font stylesheet only when this is set.
			$this->iconFontAssets->markUsed();

			return sprintf(
				// The wrapper names the Lucide family itself, so the glyph renders in the icon font
				// wherever it appears (public page or canvas) without relying on a class map.
				'<span class="iikiti-icon-font" data-icon="%s" aria-hidden="true" style="font-family:lucide;font-style:normal;line-height:1;">%s</span>',
				htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
				$glyph,
			);
		}

		return $resolved['set']->svg($resolved['name'], $size, $color ?? 'currentColor', $strokeWidth ?? 2.0) ?? '';
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function embed(string $url, array $options = []): string
	{
		return $this->embedResolver->resolve($url, $options);
	}

	/**
	 * @param array<string,mixed> $definition
	 *
	 * @return list<array<string,mixed>>
	 */
	public function query(array $definition): array
	{
		$siteId = null;
		if (SiteRegistry::hasCurrent()) {
			$siteId = (int) SiteRegistry::getCurrent()->getId();
		}

		return $this->queryExecutor->execute(QueryDefinition::fromArray($definition), $siteId);
	}

	/**
	 * @param list<string> $allowedTypes
	 */
	public function region(
		Environment $twig,
		string $id,
		string $role,
		string $name,
		array $allowedTypes = [],
		string $html = '',
	): string {
		$classes = ['iikiti-region', 'iikiti-region--'.$this->sanitize($id)];
		if ('' !== $role) {
			$classes[] = 'iikiti-region--role-'.$this->sanitize($role);
		}

		$attributes = 'class="'.htmlspecialchars(implode(' ', $classes), ENT_QUOTES).'"';

		$canEdit = $twig->getGlobals()['iikiti_can_edit'] ?? false;

		if ($canEdit) {
			$attributes .= ' data-component="BlockEditorComponent"';
			$attributes .= ' data-region-id="'.htmlspecialchars($id, ENT_QUOTES).'"';
			$attributes .= ' data-region-role="'.htmlspecialchars($role, ENT_QUOTES).'"';
			$attributes .= ' data-region-name="'.htmlspecialchars($name, ENT_QUOTES).'"';
			$attributes .= ' data-allowed-types="'.
				htmlspecialchars(implode(',', $allowedTypes), ENT_QUOTES).'"';
		}

		return '<section '.$attributes.'>'.$html.'</section>';
	}

	private function sanitize(string $value): string
	{
		return (string) preg_replace('/[^a-z0-9_-]/i', '-', $value);
	}
}
