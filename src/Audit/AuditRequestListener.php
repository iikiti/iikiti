<?php

namespace iikiti\CMS\Audit;

use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Console\ConsoleEvents;

/**
 * Groups every audit change made during one HTTP request or CLI command under a
 * single top-level event.
 *
 * The event is opened as a generic "request" entry and only persisted if at
 * least one change was recorded. Callers that know the human meaning of the
 * request (login, logout) open their own named event first; this listener then
 * leaves it alone.
 */
class AuditRequestListener implements EventSubscriberInterface
{
	/** @var list<string> */
	private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

	public function __construct(
		private readonly AuditLogger $logger,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::REQUEST => ['onRequest', 256],
			KernelEvents::TERMINATE => ['onTerminate', -256],
			ConsoleEvents::COMMAND => ['onCommand', 256],
			ConsoleEvents::TERMINATE => ['onConsoleTerminate', -256],
		];
	}

	public function onRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();

		// Safe methods only navigate or read; they are not audit-worthy on their own.
		// A write made during such a request is still recorded as a sub-event of
		// whichever event the request opens below.
		if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
			return;
		}

		$this->logger->openEvent('request', sprintf('%s %s', $request->getMethod(), $request->getPathInfo()));
	}

	public function onTerminate(TerminateEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$this->logger->closeEvent();
	}

	public function onCommand(ConsoleCommandEvent $event): void
	{
		$this->logger->openEvent('command', sprintf('Ran command %s', $event->getCommand()?->getName() ?? 'unknown'));
	}

	public function onConsoleTerminate(ConsoleTerminateEvent $event): void
	{
		$this->logger->closeEvent();
	}
}
