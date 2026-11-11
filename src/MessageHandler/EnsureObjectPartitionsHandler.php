<?php

declare(strict_types=1);

namespace iikiti\CMS\MessageHandler;

use iikiti\CMS\Message\EnsureObjectPartitions;
use iikiti\CMS\Service\ObjectPartitionMaintenance;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EnsureObjectPartitionsHandler
{
	public function __construct(
		private ObjectPartitionMaintenance $maintenance,
	) {
	}

	public function __invoke(EnsureObjectPartitions $message): void
	{
		$this->maintenance->ensureUpcoming();
	}
}
