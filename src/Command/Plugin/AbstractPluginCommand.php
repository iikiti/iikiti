<?php

namespace iikiti\CMS\Command\Plugin;

use iikiti\CMS\Plugin\PluginContainerRebuilder;
use iikiti\CMS\Plugin\PluginDownloader;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared plumbing for the iikiti:plugin:* commands.
 */
abstract class AbstractPluginCommand extends Command
{
	public function __construct(
		protected readonly PluginManager $pluginManager,
		protected readonly PluginDownloader $pluginDownloader,
		protected readonly PluginRegistry $pluginRegistry,
		protected readonly PluginContainerRebuilder $containerRebuilder,
	) {
		parent::__construct();
	}

	/**
	 * Warn the user when the previous operation scheduled a container rebuild
	 * that failed.
	 */
	protected function warnIfRebuildFailed(SymfonyStyle $io): void
	{
		if (!$this->containerRebuilder->wasLastRebuildSuccessful()) {
			$io->warning('The container could not be rebuilt automatically. Run "php bin/console cache:clear" before the changes take effect.');
		}
	}

	protected function configureSiteOptions(): void
	{
		$this->
			addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit the operation to one or more site ids.')->
			addOption('all-sites', null, InputOption::VALUE_NONE, 'Apply the operation to every site (default).');
	}

	/**
	 * Resolve the site ids targeted by the current invocation.
	 *
	 * @return list<string>|null null means "all sites"
	 */
	protected function resolveSiteIds(InputInterface $input): ?array
	{
		/** @var list<string> $sites */
		$sites = $input->getOption('site');
		$allSites = (bool) $input->getOption('all-sites');

		if ($allSites || [] === $sites) {
			return null;
		}

		return array_map('strval', $sites);
	}

	/**
	 * @param list<string>|null $siteIds null means "all sites"
	 */
	protected function describeScope(?array $siteIds): string
	{
		return null === $siteIds ? 'all sites' : 'sites: '.implode(', ', $siteIds);
	}
}
