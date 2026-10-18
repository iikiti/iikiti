<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use iikiti\CMS\Batch\BatchJobFinishedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: BatchJobFinishedEvent::class)]
final class SiteTransferCredentialCleanupListener
{
	public function __construct(private readonly SiteTransferCredentialVault $credentials)
	{
	}

	public function __invoke(BatchJobFinishedEvent $event): void
	{
		$this->credentials->delete($event->jobId);
	}
}
