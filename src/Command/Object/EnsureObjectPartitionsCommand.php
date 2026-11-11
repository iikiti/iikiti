<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Object;

use iikiti\CMS\Service\ObjectPartitionMaintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:objects:ensure-partitions',
	description: 'Ensure current and upcoming site/object quarter partitions exist.',
)]
final class EnsureObjectPartitionsCommand extends Command
{
	public function __construct(
		private readonly ObjectPartitionMaintenance $maintenance,
	) {
		parent::__construct();
	}

	protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$count = $this->maintenance->ensureUpcoming();

		$io->success(sprintf('Ensured quarterly object partitions for %d site/quarter combinations.', $count));

		return Command::SUCCESS;
	}
}
