<?php

declare(strict_types=1);

namespace iikiti\CMS\Profiler;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/** Adds query snapshots only to authenticated development proxy responses. */
final readonly class ProfilerQueryDiagnosticsSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private DebugDataHolder $debugDataHolder,
		private ProfilerQueryDiagnostics $diagnostics,
		private Profiler $profiler,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [KernelEvents::RESPONSE => ['onKernelResponse', -1023]];
	}

	public function onKernelResponse(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		$response = $event->getResponse();
		if (!$response instanceof JsonResponse ||
			!$this->profiler->isEnabled() ||
			!$this->diagnostics->isTrustedRequest($request)) {
			return;
		}

		try {
			$data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($data)) {
				return;
			}
			$context = $request->attributes->get(ProfilerQueryDiagnostics::RENDER_CONTEXT_ATTRIBUTE);
			$data[ProfilerQueryDiagnostics::QUERY_DATA_KEY] = $this->diagnostics->snapshot(
				$this->debugDataHolder->getData(),
				is_string($context) ? $context : null,
			);
			$response->setData($data);
		} catch (\Throwable) {
			// Profiling must never change the API response or page-render result.
		}
	}
}
