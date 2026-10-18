<?php

declare(strict_types=1);

namespace iikiti\CMS\Transfer;

use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\BatchMessageContext;
use iikiti\CMS\Batch\BatchMessageHandlerInterface;

final class SiteTransferExportRecordHandler implements BatchMessageHandlerInterface
{
	public function __construct(private readonly SiteTransferManifestStore $store)
	{
	}

	public function getType(): string
	{
		return 'site_transfer.export_record';
	}

	public function handle(BatchMessage $message, BatchMessageContext $context): void
	{
		$header = $message->payload['header'] ?? null;
		$record = $message->payload['record'] ?? null;
		if (!is_array($header) || !is_array($record)) {
			throw new \InvalidArgumentException('Export record payload is malformed.');
		}
		if (isset($record['asset']['downloadPath'])) {
			$record['asset']['downloadPath'] = str_replace('{exportId}', (string) $context->batchId, $record['asset']['downloadPath']);
		}
		$this->store->append($context->batchId, $header, $record);
	}
}
