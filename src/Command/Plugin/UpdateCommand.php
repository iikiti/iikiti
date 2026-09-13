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
	name: 'iikiti:plugin:update',
	description: 'Check for and apply plugin updates.',
)]
class UpdateCommand extends AbstractPluginCommand
{
	protected function configure(): void
	{
		$this->
			addArgument('slug', InputArgument::OPTIONAL, 'Update a single plugin (defaults to all installed plugins).')->
			addOption('version', null, InputOption::VALUE_REQUIRED, 'Force a specific target version.')->
			addOption('store', null, InputOption::VALUE_REQUIRED, 'Override the store URL (non-production only).')->
			addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report available updates.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = $input->getArgument('slug');
		$forcedVersion = $input->getOption('version');
		$storeUrl = $input->getOption('store');
		$dryRun = (bool) $input->getOption('dry-run');

		$installed = $this->pluginManager->getInstalled();
		if (null === $slug && null !== $forcedVersion) {
			$io->error('The --version option requires a single plugin slug to be specified.');

			return self::FAILURE;
		}
		if (null !== $slug) {
			if (!isset($installed[$slug])) {
				$io->error(sprintf('Plugin "%s" is not installed.', $slug));

				return self::FAILURE;
			}
			$installed = [$slug => $installed[$slug]];
		}

		if ([] === $installed) {
			$io->warning('No plugins are installed.');

			return self::SUCCESS;
		}

		$updated = 0;
		foreach ($installed as $candidateSlug => $plugin) {
			$currentVersion = $plugin['manifest']->version;

			try {
				$targetVersion = $forcedVersion;
				if (null === $targetVersion) {
					$update = $this->pluginDownloader->checkForUpdate($candidateSlug, $currentVersion, $storeUrl);
					if (null === $update || !isset($update['version'])) {
						$io->text(sprintf('%s is up to date (%s).', $candidateSlug, $currentVersion));
						continue;
					}
					$targetVersion = (string) $update['version'];
				}

				if ($targetVersion === $currentVersion) {
					$io->text(sprintf('%s is already at %s.', $candidateSlug, $currentVersion));
					continue;
				}

				if ($dryRun) {
					$io->text(sprintf('%s: %s -> %s (dry run).', $candidateSlug, $currentVersion, $targetVersion));
					continue;
				}

				$io->text(sprintf('Updating %s: %s -> %s...', $candidateSlug, $currentVersion, $targetVersion));
				$package = $this->pluginDownloader->download($candidateSlug, $targetVersion, $storeUrl);
				$this->pluginManager->updatePackage($package);
				++$updated;
			} catch (PluginException $exception) {
				$io->error(sprintf('%s: %s', $candidateSlug, $exception->getMessage()));

				return self::FAILURE;
			}
		}

		if (!$dryRun) {
			$io->success(sprintf('Updated %d plugin(s).', $updated));
			$this->warnIfRebuildFailed($io);
		}

		return self::SUCCESS;
	}
}
