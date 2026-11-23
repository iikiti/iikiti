<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Fragment;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginActivationChecker;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Shared local dispatcher used by Twig, API routes, and consumer relays. */
final readonly class FragmentRuntime
{
	public function __construct(
		private FragmentProviderRegistry $providers,
		private ?PluginActivationChecker $activationChecker = null,
	) {
	}

	/** @throws NotFoundHttpException when the provider is not explicitly registered */
	public function render(string $name, FragmentContext $context): FragmentResult
	{
		$provider = $this->providers->get($name);
		if (null === $provider || !$this->isAvailable($name, $context->site)) {
			throw new NotFoundHttpException('The requested fragment provider does not exist.');
		}

		return $provider->render($context);
	}

	public function has(string $name): bool
	{
		return $this->providers->has($name);
	}

	public function isAvailable(string $name, ?Site $site): bool
	{
		$pluginSlug = strstr($name, '.', true);
		if (!$this->providers->has($name) || null === $site || !is_string($pluginSlug)) {
			return false;
		}

		if (null !== $this->activationChecker) {
			return $this->activationChecker->isActiveForSite($pluginSlug, $site);
		}

		return in_array($pluginSlug, $site->getEnabledPlugins(), true);
	}

	public function isInlineable(string $name, ?\iikiti\CMS\Entity\Object\Site $site): bool
	{
		if (!$this->isAvailable($name, $site)) {
			return false;
		}

		return $this->providers->get($name) instanceof InlineableFragmentProviderInterface;
	}
}
