<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Fragment;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Web\BlockEditor\Render\CustomCssCollector;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Twig entry point for declaring or rendering a named Fragment API provider. */
final class FragmentTwigExtension extends AbstractExtension
{
	public function __construct(
		#[AutowireServiceClosure(FragmentRuntime::class)]
		private readonly \Closure $fragmentRuntime,
		private readonly RequestStack $requests,
	) {
	}

	public function getFunctions(): array
	{
		return [new TwigFunction('iikiti_fragment', $this->fragment(...), [
			'needs_environment' => true,
			'needs_context' => true,
			'is_safe' => ['html'],
		])];
	}

	/** @param array<string,mixed> $twigContext
	 * @param array<string,mixed> $params
	 * @param array<string,mixed> $options
	 */
	public function fragment(Environment $twig, array $twigContext, string $provider, array $params = [], array $options = []): string
	{
		$delivery = $options['delivery'] ?? 'server';
		$key = $options['key'] ?? 'fragment-'.bin2hex(random_bytes(8));
		$dependsOn = $options['dependsOn'] ?? [];
		if (!in_array($delivery, ['server', 'browser'], true) ||
			!is_string($key) ||
			1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) ||
			!is_array($dependsOn) ||
			!array_is_list($dependsOn)) {
			throw new \InvalidArgumentException('Fragment placement options are invalid.');
		}
		$baseContext = $twigContext['_iikiti_fragment_context'] ?? null;
		if (!$baseContext instanceof FragmentContext) {
			$site = $twigContext['iikiti_site'] ?? (SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null);
			if (null !== $site && !$site instanceof Site) {
				$site = null;
			}
			$locale = $twigContext['iikiti_locale'] ?? $this->requests->getCurrentRequest()?->getLocale() ?? 'und';
			$baseContext = new FragmentContext($site, (string) $locale);
		}
		$fragments = ($this->fragmentRuntime)();
		if (!$fragments->isAvailable($provider, $baseContext->site)) {
			throw new \InvalidArgumentException(sprintf('Fragment provider "%s" is not available for this site.', $provider));
		}
		$collector = $twigContext['_iikiti_fragment_plan'] ?? null;
		if ($collector instanceof FragmentPlanCollector) {
			return $collector->declare($provider, $params, $options + ['key' => $key]);
		}
		if ('browser' === $delivery) {
			return FragmentPlanCollector::browserPlaceholder($provider, $params, $key, $dependsOn);
		}

		$result = $fragments->render($provider, new FragmentContext($baseContext->site, $baseContext->locale, $params));
		$styles = $twigContext['_iikiti_fragment_styles'] ?? null;
		if ($styles instanceof CustomCssCollector) {
			foreach ($result->styles as $style) {
				$styles->append($style['css'], $style['id']);
			}
		}

		return $result->html;
	}
}
