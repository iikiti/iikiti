<?php

namespace iikiti\CMS\Command\Plugin;

use iikiti\CMS\Plugin\PluginState;
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

		$installed = $this->pluginManager->getInstalled();
		if ([] === $installed) {
			$io->warning('No plugins are installed.');

			return self::SUCCESS;
		}

		$activeByPlugin = $this->pluginManager->getActiveSiteIdsByPlugin();

		$rows = [];
		foreach ($installed as $slug => $plugin) {
			$manifest = $plugin['manifest'];
			$installInfo = $plugin['installInfo'];

			$activeSiteIds = $activeByPlugin[$slug] ?? [];
			if (null !== $siteFilter) {
				$activeSiteIds = array_values(array_intersect($activeSiteIds, $siteFilter));
			}

			$rows[] = [
				$slug,
				$manifest->version,
				$manifest->edition,
				$installInfo?->source->value ?? 'manual',
				$installInfo?->state->value ?? PluginState::PendingReview->value,
				[] === $activeSiteIds ? '-' : implode(', ', $activeSiteIds),
			];
		}

		$io->table(
			['Slug', 'Version', 'Edition', 'Source', 'State', 'Active sites'],
			$rows,
		);

		$io->text(sprintf('%d plugin(s) installed, %d loaded into the current container.', count($rows), $this->pluginRegistry->count()));

		return self::SUCCESS;
	}
}
