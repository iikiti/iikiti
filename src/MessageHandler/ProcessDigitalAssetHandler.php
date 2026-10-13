<?php

declare(strict_types=1);

namespace iikiti\CMS\MessageHandler;

use iikiti\CMS\Message\ProcessDigitalAsset;
use iikiti\CMS\Service\DigitalAssetWorkflowManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessDigitalAssetHandler
{
	public function __construct(private readonly DigitalAssetWorkflowManager $workflowManager)
	{
	}

	public function __invoke(ProcessDigitalAsset $message): void
	{
		$this->workflowManager->process($message->assetId);
	}
}
