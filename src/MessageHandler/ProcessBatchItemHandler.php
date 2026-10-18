<?php

declare(strict_types=1);

namespace iikiti\CMS\MessageHandler;

use iikiti\CMS\Batch\BatchManager;
use iikiti\CMS\Message\ProcessBatchItem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessBatchItemHandler
{
	public function __construct(private readonly BatchManager $batches)
	{
	}

	public function __invoke(ProcessBatchItem $message): void
	{
		$this->batches->processItem($message->itemId);
	}
}
