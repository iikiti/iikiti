<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Subscriber;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\SecurityHeaderPolicy;
use iikiti\CMS\Service\SecurityHeaderStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Emits site-scoped security headers on kernel.response after site resolution.
 *
 * Headers are emitted only when the effective mode is `on` and the request is
 * secure with a successful response. A per-request CSP nonce is generated and
 * injected into inline `<script>`/`<style>` tags that lack one.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private readonly SecurityHeaderStore $store,
		private readonly SecurityHeaderPolicy $policy,
		#[Autowire('%iikiti_security_headers%')]
		private readonly string $envMode,
	) {
	}

	#[\Override]
	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::RESPONSE => ['onKernelResponse', -10],
		];
	}

	public function onKernelResponse(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		if (!SiteRegistry::hasCurrent()) {
			return;
		}

		$site = SiteRegistry::getCurrent();
		$isSecure = $request->isSecure();
		$response = $event->getResponse();
		$isSuccessful = $response->isSuccessful();

		$envMode = $this->policy->normalizeEnvMode($this->envMode);
		$siteMode = $this->siteMode($site);

		$mode = $this->policy->effectiveMode($envMode, $siteMode, $isSecure, $isSuccessful);
		if ('off' === $mode) {
			return;
		}

		$nonce = base64_encode(random_bytes(16));
		$headers = $this->policy->renderHeaders(
			$this->store->getPolicy($site)['headers'],
			$mode,
			$nonce,
		);

		foreach ($headers as $name => $value) {
			$response->headers->set($name, $value);
		}

		$this->injectNonce($response, $nonce);
	}

	private function siteMode(Site $site): string
	{
		$config = $site->getConfiguration();
		$mode = $config->get('security_headers_mode');
		if (!is_string($mode) || '' === $mode) {
			return SecurityHeaderPolicy::SITE_MODE_DEFAULT;
		}
		if (!in_array($mode, [
			SecurityHeaderPolicy::SITE_MODE_DEFAULT,
			SecurityHeaderPolicy::SITE_MODE_FORCED_OFF,
			SecurityHeaderPolicy::SITE_MODE_FORCED_ON,
		], true)) {
			return SecurityHeaderPolicy::SITE_MODE_DEFAULT;
		}

		return $mode;
	}

	/**
	 * Inject the CSP nonce into inline `<script>` and `<style>` tags that lack
	 * one, so nonce-based CSP works without template changes.
	 */
	private function injectNonce(Response $response, string $nonce): void
	{
		$contentType = $response->headers->get('Content-Type');
		if (!is_string($contentType) || !str_starts_with($contentType, 'text/html')) {
			return;
		}

		$html = $response->getContent();
		if (false === $html || '' === $html) {
			return;
		}

		$html = preg_replace_callback(
			'/<(script|style)\b([^>]*?)\/?>/i',
			static function (array $matches) use ($nonce): string {
				$tag = $matches[1];
				$attrs = $matches[2];
				if (str_contains($attrs, 'nonce=')) {
					return $matches[0];
				}

				return sprintf('<%s%s nonce="%s">', $tag, $attrs, $nonce);
			},
			$html,
		);

		$response->setContent($html);
	}
}
