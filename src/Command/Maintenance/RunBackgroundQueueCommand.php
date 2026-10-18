<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Maintenance;

use iikiti\CMS\Maintenance\BackgroundQueueRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'iikiti:maintenance:run', description: 'Run one bounded tick of due schedules and background queues.')]
final class RunBackgroundQueueCommand extends Command
{
	public function __construct(private readonly BackgroundQueueRunner $runner)
	{
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$processed = $this->runner->run();
		(new SymfonyStyle($input, $output))->success(sprintf('Maintenance tick finished; handled %d queued message(s).', $processed));

		return Command::SUCCESS;
	}
}
