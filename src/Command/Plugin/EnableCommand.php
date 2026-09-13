<?php

namespace iikiti\CMS\Command\Plugin;

use iikiti\CMS\Plugin\Exception\PluginException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:plugin:enable',
	description: 'Enable an installed plugin for one or more sites (default: all sites).',
)]
class EnableCommand extends AbstractPluginCommand
{
	protected function configure(): void
	{
		$this->addArgument('slug', InputArgument::REQUIRED, 'The plugin slug to enable.');
		$this->configureSiteOptions();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = (string) $input->getArgument('slug');
		$siteIds = $this->resolveSiteIds($input);

		try {
			if (null === $siteIds) {
				$count = $this->pluginManager->activateForAllSites($slug);
				$io->success(sprintf('Enabled "%s" for %d site(s).', $slug, $count));
			} else {
				$count = $this->pluginManager->activateForSites($slug, $siteIds);
				$io->success(sprintf('Enabled "%s" for %d site(s).', $slug, $count));
			}
			$this->warnIfRebuildFailed($io);
		} catch (PluginException $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
