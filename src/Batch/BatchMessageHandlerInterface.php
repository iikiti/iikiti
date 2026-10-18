<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('iikiti.batch.message_handler')]
interface BatchMessageHandlerInterface
{
	public function getType(): string;

	public function handle(BatchMessage $message, BatchMessageContext $context): void;
}
