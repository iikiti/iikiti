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
 * Rebuilds a search index from source data.
 */
#[AsCommand('iikiti:search:index:rebuild', description: 'Rebuild a search index')]
final class IndexRebuildCommand extends AbstractSearchCommand
{
	protected function configure(): void
	{
		$this->
			addArgument('slug', InputArgument::OPTIONAL, 'The slug or ID of the search index to rebuild. Omit to rebuild all.')->
			addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to specific sites.')->
			addOption('all-sites', null, InputOption::VALUE_NONE, 'Apply to all sites (default).')->
			addOption('site-host', null, InputOption::VALUE_REQUIRED, 'Use this site host as API context.')->
			addOption('async', null, InputOption::VALUE_NONE, 'Queue the rebuild for asynchronous processing.')->
			setHelp('Rebuilds the search index by re-reading all source objects and re-indexing them.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = $input->getArgument('slug');
		$siteIds = $this->resolveSiteIds($input);

		if (null === $slug) {
			// Rebuild all
			$response = $this->requestSearchAction('/api/admin/search/rebuild-all', $input, ['siteIds' => $siteIds]);
			$count = $response['result']['count'] ?? 0;
			$io->success(sprintf('Rebuilt %d search index(es).', $count));

			return Command::SUCCESS;
		}

		$index = $this->resolveIndex($slug, $siteIds, $input);
		if (null === $index) {
			$io->error(sprintf('Search index "%s" not found.', $slug));

			return Command::INVALID;
		}

		try {
			$this->requestSearchAction(sprintf('/api/admin/search/indexes/%d/rebuild', $index['id']), $input);
			$io->success(sprintf('Rebuilt search index "%s" for %s.', $index['name'], $this->describeScope($siteIds)));
		} catch (\Throwable $e) {
			$io->error($this->formatActionError('Failed to rebuild index: ', $e));

			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}
}
