<?php

namespace iikiti\CMS\Command\Search;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Repository\SearchIndexRepository;
use iikiti\CMS\Search\Strategy\SearchEngineRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared plumbing for the iikiti:search:* commands.
 */
abstract class AbstractSearchCommand extends Command
{
	public function __construct(
		protected readonly SearchIndexRepository $indexRepository,
		protected readonly SearchEngineRegistry $engineRegistry,
		protected readonly EntityManagerInterface $entityManager,
	) {
		parent::__construct();
	}

	protected function configureSiteOptions(): void
	{
		$this->
			addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit the operation to one or more site ids.')->
			addOption('all-sites', null, InputOption::VALUE_NONE, 'Apply the operation to every site (default).');
	}

	/**
	 * @return list<int|string>|null null means "all sites"
	 */
	protected function resolveSiteIds(InputInterface $input): ?array
	{
		/** @var list<string> $sites */
		$sites = $input->getOption('site');
		$allSites = (bool) $input->getOption('all-sites');

		if ($allSites || [] === $sites) {
			return null;
		}

		return array_map(static function (string $site): string { return $site; }, $sites);
	}

	/**
	 * @param list<int|string>|null $siteIds
	 */
	protected function resolveIndex(SymfonyStyle $io, string $slugOrId, ?array $siteIds = null): ?SearchIndex
	{
		// Try by id first
		if (ctype_digit($slugOrId)) {
			$index = $this->indexRepository->find((int) $slugOrId);
			if (null !== $index) {
				return $index;
			}
		}

		// Fall back to slug lookup
		$siteId = null;
		if (null !== $siteIds && 1 === count($siteIds)) {
			$siteId = $siteIds[0];
		}

		return $this->indexRepository->findBySlug($slugOrId, $siteId);
	}

	/**
	 * @param list<int|string>|null $siteIds
	 */
	protected function describeScope(?array $siteIds): string
	{
		return null === $siteIds ? 'all sites' : 'sites: '.implode(', ', $siteIds);
	}

	/**
	 * @return list<SearchIndex>
	 */
	protected function listIndexes(SymfonyStyle $io): array
	{
		/** @var list<SearchIndex> $indexes */
		$indexes = $this->indexRepository->findBy([], ['name' => 'ASC']);

		if ([] === $indexes) {
			$io->warning('No search index configurations found. Run "iikiti:search:config:init" to create defaults.');

			return [];
		}

		$rows = [];
		foreach ($indexes as $index) {
			$rows[] = [
				$index->getId(),
				$index->getSlug(),
				$index->getName(),
				$index->getType()->getLabel(),
				$index->getEngine(),
				$index->isEnabled() ? '<info>Yes</info>' : '<comment>No</comment>',
				$index->isSystemLocked() ? '<info>Yes</info>' : '<comment>No</comment>',
			];
		}

		$io->table(
			['ID', 'Slug', 'Name', 'Type', 'Engine', 'Enabled', 'System Locked'],
			$rows
		);

		return $indexes;
	}
}
