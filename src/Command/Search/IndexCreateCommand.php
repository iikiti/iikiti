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
 * Creates the search index table for a configuration.
 */
#[AsCommand('iikiti:search:index:create', description: 'Create a search index table')]
final class IndexCreateCommand extends AbstractSearchCommand
{
	protected function configure(): void
	{
		$this->
			addArgument('slug', InputArgument::REQUIRED, 'The slug of the search index to create.')->
			addOption('engine', null, InputOption::VALUE_REQUIRED, 'Override the search engine (default: postgresql).', 'postgresql')->
			addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to specific sites.')->
			addOption('all-sites', null, InputOption::VALUE_NONE, 'Apply to all sites (default).')->
			addOption('site-host', null, InputOption::VALUE_REQUIRED, 'Use this site host as API context.')->
			setHelp('Creates the search index table and indexes for the given configuration.');
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

		if (!($index['isEnabled'] ?? true)) {
			$io->warning(sprintf('Search index "%s" is disabled. Enable it first.', $slug));

			return Command::INVALID;
		}

		try {
			$this->requestSearchAction(sprintf('/api/admin/search/indexes/%d/create', $index['id']), $input);
			$io->success(sprintf('Created search index "%s" (%s) for %s.', $index['name'], $index['engine'], $this->describeScope($siteIds)));
		} catch (\Throwable $e) {
			$io->error($this->formatActionError('Failed to create index: ', $e));

			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}
}
