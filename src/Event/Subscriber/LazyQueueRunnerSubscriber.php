<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Subscriber;

use iikiti\CMS\Maintenance\BackgroundQueueRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final class LazyQueueRunnerSubscriber
{
	public function __construct(
		private readonly BackgroundQueueRunner $runner,
		#[Autowire('%lazy_queue_enabled%')]
		private readonly bool $enabled,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	public function __invoke(TerminateEvent $event): void
	{
		if (!$this->enabled || !$event->isMainRequest()) {
			return;
		}

		try {
			$this->runner->run();
		} catch (\Throwable $exception) {
			$this->logger?->error('Lazy background queue tick failed after the response was sent.', ['exception' => $exception]);
		}
	}
}
