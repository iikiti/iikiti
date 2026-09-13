<?php

namespace iikiti\CMS\Command\Plugin;

use iikiti\CMS\Plugin\Exception\PluginException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:plugin:disable',
	description: 'Disable a plugin for one or more sites (default: all sites).',
)]
class DisableCommand extends AbstractPluginCommand
{
	protected function configure(): void
	{
		$this->addArgument('slug', InputArgument::REQUIRED, 'The plugin slug to disable.');
		$this->configureSiteOptions();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = (string) $input->getArgument('slug');
		$siteIds = $this->resolveSiteIds($input);

		try {
			if (null === $siteIds) {
				$count = $this->pluginManager->disableForAllSites($slug);
				$io->success(sprintf('Disabled "%s" for %d site(s).', $slug, $count));
			} else {
				$count = $this->pluginManager->disableForSites($slug, $siteIds);
				$io->success(sprintf('Disabled "%s" for %d site(s).', $slug, $count));
			}
			$this->warnIfRebuildFailed($io);
		} catch (PluginException $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
