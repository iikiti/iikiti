<?php

declare(strict_types=1);

namespace iikiti\CMS\MessageHandler;

use iikiti\CMS\Message\ProcessDigitalAsset;
use iikiti\CMS\Service\DigitalAssetProcessorClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessDigitalAssetHandler
{
	public function __construct(private readonly DigitalAssetProcessorClient $processor)
	{
	}

	public function __invoke(ProcessDigitalAsset $message): void
	{
		$this->processor->process($message->assetId);
	}
}
