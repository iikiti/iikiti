<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Subscriber;

use iikiti\CMS\Service\RemoteResponseHeaderResolver;
use iikiti\CMS\Service\ResponseHeaderApplicator;
use iikiti\CMS\Service\ResponseHeaderResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SecurityHeadersSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private ResponseHeaderResolver $resolver,
		private RemoteResponseHeaderResolver $remote,
		private ResponseHeaderApplicator $applicator,
		private LoggerInterface $logger,
		#[Autowire('%application_role%')] private string $applicationRole,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
	}

	public function onKernelResponse(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		$internal = RemoteResponseHeaderResolver::PATH === $request->getPathInfo();
		if ($internal && 'consumer' === $this->applicationRole) {
			return;
		}
		$response = $event->getResponse();
		try {
			$result = 'consumer' === $this->applicationRole ?
				$this->remote->resolve($request, $response) :
				$this->resolver->resolve($request, $response, $event->isMainRequest() && !$internal);
			$this->applicator->apply($request, $response, $result['rules'], $result['siteMode']);
			$response->setVary($result['vary'], false);
		} catch (\Throwable $exception) {
			$this->logger->error('Response header resolution failed: {error}', ['error' => $exception->getMessage()]);
			if (!$response->isSuccessful()) {
				$response->headers->set('Cache-Control', 'no-store');

				return;
			}
			$event->setResponse(new Response('Response header configuration unavailable.', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']));
		}
	}
}
