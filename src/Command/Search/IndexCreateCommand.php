<?php

namespace iikiti\CMS\Command\Search;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Search\Repository\SearchIndexRepository;
use iikiti\CMS\Search\Service\IndexManager;
use iikiti\CMS\Search\Strategy\SearchEngineRegistry;
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
	public function __construct(
		SearchIndexRepository $indexRepository,
		EntityManagerInterface $entityManager,
		SearchEngineRegistry $engineRegistry,
		private readonly IndexManager $indexManager,
	) {
		parent::__construct($indexRepository, $engineRegistry, $entityManager);
	}

	protected function configure(): void
	{
		$this->
			addArgument('slug', InputArgument::REQUIRED, 'The slug of the search index to create.')->
			addOption('engine', null, InputOption::VALUE_REQUIRED, 'Override the search engine (default: postgresql).', 'postgresql')->
			addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to specific sites.')->
			addOption('all-sites', null, InputOption::VALUE_NONE, 'Apply to all sites (default).')->
			setHelp('Creates the search index table and indexes for the given configuration.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = $input->getArgument('slug');
		$siteIds = $this->resolveSiteIds($input);

		$index = $this->resolveIndex($io, (string) $slug, $siteIds);
		if (null === $index) {
			$io->error(sprintf('Search index "%s" not found.', $slug));

			return Command::INVALID;
		}

		if (!$index->isEnabled()) {
			$io->warning(sprintf('Search index "%s" is disabled. Enable it first.', $slug));

			return Command::INVALID;
		}

		try {
			$this->indexManager->createIndex($index->getId());
			$io->success(sprintf('Created search index "%s" (%s) for %s.', $index->getName(), $index->getEngine(), $this->describeScope($siteIds)));
		} catch (\Throwable $e) {
			$io->error(sprintf('Failed to create index: %s', $e->getMessage()));

			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}
}
