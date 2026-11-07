<?php

namespace iikiti\CMS\Command\Search;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops the search index table for a configuration.
 */
#[AsCommand('iikiti:search:index:drop', description: 'Drop a search index table')]
final class IndexDropCommand extends AbstractSearchCommand
{
	protected function configure(): void
	{
		$this->
			addArgument('slug', InputArgument::REQUIRED, 'The slug of the search index to drop.')->
			addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt.')->
			addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to specific sites.')->
			addOption('all-sites', null, InputOption::VALUE_NONE, 'Apply to all sites (default).')->
			addOption('site-host', null, InputOption::VALUE_REQUIRED, 'Use this site host as API context.')->
			setHelp('Drops the search index table for the given configuration.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = $input->getArgument('slug');
		$siteIds = $this->resolveSiteIds($input);

		$index = $this->resolveIndex((string) $slug, $siteIds, $input);
		if (null === $index) {
			$io->error(sprintf('Search index "%s" not found.', $slug));

			return Command::INVALID;
		}

		if ($index['systemLocked'] ?? false) {
			$io->error(sprintf('Search index "%s" is system-locked and cannot be dropped. Use the admin UI to manage fields instead.', $slug));

			return Command::INVALID;
		}

		if (!$input->getOption('force')) {
			if (!$io->confirm(sprintf('Drop search index "%s"? This will remove all indexed data.', $slug), false)) {
				$io->note('Operation cancelled.');

				return Command::SUCCESS;
			}
		}

		try {
			$this->requestSearchAction(sprintf('/api/admin/search/indexes/%d/drop', $index['id']), $input);
			$io->success(sprintf('Dropped search index "%s".', $index['name']));
		} catch (\Throwable $e) {
			$io->error($this->formatActionError('Failed to drop index: ', $e));

			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}
}
