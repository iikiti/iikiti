<?php

namespace iikiti\CMS\Command\Search;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists all search index configurations.
 */
#[AsCommand('iikiti:search:config:list', description: 'List search index configurations')]
final class ConfigListCommand extends AbstractSearchCommand
{
	protected function configure(): void
	{
		$this->
			setHelp('Lists all search index configurations with their type, engine, and status.')->
			addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by index type (frontend, admin, custom).')->
			addOption('engine', null, InputOption::VALUE_REQUIRED, 'Filter by search engine.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$indexes = $this->listIndexes($io);
		if ([] === $indexes) {
			return Command::SUCCESS;
		}

		return Command::SUCCESS;
	}
}
