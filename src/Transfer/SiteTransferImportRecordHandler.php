<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\BatchMessageContext;
use iikiti\CMS\Batch\BatchMessageHandlerInterface;

final class SiteTransferImportRecordHandler implements BatchMessageHandlerInterface
{
	public function __construct(private readonly SiteTransferImporter $importer)
	{
	}

	public function getType(): string
	{
		return 'site_transfer.import_record';
	}

	public function handle(BatchMessage $message, BatchMessageContext $context): void
	{
		$this->importer->importRecord($message->payload, $context);
	}
}
