<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Twig;

use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\BlockEditor\Embed\EmbedResolver;
use iikiti\CMS\Web\BlockEditor\Query\QueryDefinition;
use iikiti\CMS\Web\BlockEditor\Query\QueryExecutor;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers for rendering blocks in theme/layout templates:
 * - `iakitti_embed(url, options)`        -> resolved embed HTML (cached, allowlisted)
 * - `iakitti_query(definition)`          -> list of result rows for a query block
 * - `iakitti_region(id, role, name, allowed, html)` -> region wrapper markup
 */
final class BlockTwigExtension extends AbstractExtension
{
	public function __construct(
		private readonly EmbedResolver $embedResolver,
		private readonly QueryExecutor $queryExecutor,
	) {
	}

	public function getFunctions(): array
	{
		return [
			new TwigFunction('iakitti_embed', [$this, 'embed'], ['is_safe' => ['html']]),
			new TwigFunction('iakitti_query', [$this, 'query']),
			new TwigFunction(
				'iakitti_region',
				[$this, 'region'],
				['is_safe' => ['html'], 'needs_environment' => true]
			),
		];
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
		$classes = ['iakitti-region', 'iakitti-region--'.$this->sanitize($id)];
		if ('' !== $role) {
			$classes[] = 'iakitti-region--role-'.$this->sanitize($role);
		}

		$attributes = 'class="'.htmlspecialchars(implode(' ', $classes), ENT_QUOTES).'"';

		if ($twig->getGlobals()['iakitti_editor_mode'] ?? false) {
			$attributes .= ' data-component="BlockEditorComponent"';
			$attributes .= ' data-region-id="'.htmlspecialchars($id, ENT_QUOTES).'"';
			$attributes .= ' data-region-role="'.htmlspecialchars($role, ENT_QUOTES).'"';
			$attributes .= ' data-region-name="'.htmlspecialchars($name, ENT_QUOTES).'"';
			$attributes .= ' data-allowed-types="'.
				htmlspecialchars(implode(',', $allowedTypes), ENT_QUOTES).'"';
		}

		return '<section'.$attributes.'>'.$html.'</section>';
	}

	private function sanitize(string $value): string
	{
		return (string) preg_replace('/[^a-z0-9_-]/i', '-', $value);
	}
}
