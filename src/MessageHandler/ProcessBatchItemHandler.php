<?php

declare(strict_types=1);

namespace iikiti\CMS\MessageHandler;

use iikiti\CMS\Batch\BatchItemProcessor;
use iikiti\CMS\Message\ProcessBatchItem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessBatchItemHandler
{
	public function __construct(private readonly BatchItemProcessor $processor)
	{
	}

	public function __invoke(ProcessBatchItem $message): void
	{
		$this->processor->process($message->itemId);
	}
}
