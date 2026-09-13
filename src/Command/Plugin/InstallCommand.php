<?php

namespace iikiti\CMS\Command\Plugin;

use iikiti\CMS\Plugin\Exception\PluginException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:plugin:install',
	description: 'Download and install a plugin from the store.',
)]
class InstallCommand extends AbstractPluginCommand
{
	protected function configure(): void
	{
		$this->
			addArgument('slug', InputArgument::REQUIRED, 'The plugin slug to install.')->
			addOption('version', null, InputOption::VALUE_REQUIRED, 'Install a specific version (defaults to the latest).')->
			addOption('store', null, InputOption::VALUE_REQUIRED, 'Override the store URL (non-production only).')->
			addOption('no-activate', null, InputOption::VALUE_NONE, 'Install without enabling the plugin.');
		$this->configureSiteOptions();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = (string) $input->getArgument('slug');
		$version = $input->getOption('version');
		$storeUrl = $input->getOption('store');
		$activate = !$input->getOption('no-activate');
		$siteIds = $this->resolveSiteIds($input);

		try {
			if (null === $version) {
				$io->text(sprintf('Resolving latest version of "%s" from the store...', $slug));
				$update = $this->pluginDownloader->checkForUpdate($slug, '0.0.0', $storeUrl);
				if (null === $update || !isset($update['version'])) {
					$io->error(sprintf('Could not resolve a version for "%s".', $slug));

					return self::FAILURE;
				}
				$version = (string) $update['version'];
			}

			$io->text(sprintf('Downloading "%s" %s...', $slug, $version));
			$package = $this->pluginDownloader->download($slug, $version, $storeUrl);

			$manifest = $this->pluginManager->installPackage($package);
			$io->success(sprintf('Installed %s %s.', $manifest->name, $manifest->version));

			if ($activate) {
				if (null === $siteIds) {
					$count = $this->pluginManager->activateForAllSites($slug);
					$io->text(sprintf('Enabled for %d site(s).', $count));
				} else {
					$count = $this->pluginManager->activateForSites($slug, $siteIds);
					$io->text(sprintf('Enabled for %d site(s).', $count));
				}
			}
			$this->warnIfRebuildFailed($io);
		} catch (PluginException $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
