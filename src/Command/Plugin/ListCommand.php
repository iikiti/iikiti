<?php

namespace iikiti\CMS\Command\Plugin;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:plugin:list',
	description: 'List installed plugins, their versions and where they are active.',
)]
class ListCommand extends AbstractPluginCommand
{
	protected function configure(): void
	{
		$this->configureSiteOptions();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$siteFilter = $this->resolveSiteIds($input);

		try {
			$plugins = $this->collectionMembers($this->requestApi('GET', '/api/admin/plugins', siteHost: $this->siteHost($input)));
		} catch (\Throwable $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}
		if ([] === $plugins) {
			$io->warning('No plugins are installed.');

			return self::SUCCESS;
		}

		$rows = [];
		foreach ($plugins as $plugin) {
			$activeSiteIds = is_array($plugin['activeSites'] ?? null) ? $plugin['activeSites'] : [];
			if (null !== $siteFilter) {
				$activeSiteIds = array_values(array_intersect($activeSiteIds, $siteFilter));
			}

			$rows[] = [
				$plugin['slug'] ?? '',
				$plugin['version'] ?? '',
				$plugin['edition'] ?? '',
				$plugin['source'] ?? 'manual',
				$plugin['state'] ?? 'pending_review',
				[] === $activeSiteIds ? '-' : implode(', ', $activeSiteIds),
			];
		}

		$io->table(
			['Slug', 'Version', 'Edition', 'Source', 'State', 'Active sites'],
			$rows,
		);

		$loaded = count(array_filter($plugins, static fn (array $plugin): bool => (bool) ($plugin['active'] ?? false)));
		$io->text(sprintf('%d plugin(s) installed, %d loaded into the current container.', count($rows), $loaded));

		return self::SUCCESS;
	}
}
