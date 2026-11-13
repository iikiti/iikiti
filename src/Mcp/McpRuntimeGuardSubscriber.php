<?php

namespace iikiti\CMS\Mcp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/** Keep the MCP server on the API runtime even when its bundle is enabled. */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 1)]
final class McpRuntimeGuardSubscriber
{
	public function __construct(
		#[Autowire('%application_role%')]
		private readonly string $applicationRole,
		#[Autowire('%mcp_http_enabled%')]
		private readonly bool $httpEnabled,
		#[Autowire('%mcp_api_max_request_bytes%')]
		private readonly int $maxRequestBytes,
	) {
	}

	public function __invoke(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		if ('/api/mcp' !== $request->getPathInfo()) {
			return;
		}

		if ('consumer' === $this->applicationRole || !$this->httpEnabled) {
			throw new NotFoundHttpException();
		}

		$contentLengthHeader = $request->headers->get('Content-Length', '0');
		$contentLength = ctype_digit($contentLengthHeader) ? (int) $contentLengthHeader : 0;
		$queryString = $request->getQueryString() ?? '';
		if ($contentLength > $this->maxRequestBytes || strlen($queryString) > $this->maxRequestBytes) {
			throw new HttpException(413, 'MCP request exceeds the configured request size limit.');
		}
	}
}
