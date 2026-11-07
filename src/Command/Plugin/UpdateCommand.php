<?php

namespace iikiti\CMS\Command\Plugin;

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
		$this->configureSiteHostOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = $input->getArgument('slug');
		$forcedVersion = $input->getOption('version');
		$storeUrl = $input->getOption('store');
		$dryRun = (bool) $input->getOption('dry-run');

		if (null === $slug && null !== $forcedVersion) {
			$io->error('The --version option requires a single plugin slug to be specified.');

			return self::FAILURE;
		}
		try {
			$plugins = $this->collectionMembers($this->requestApi('GET', '/api/admin/plugins', siteHost: $this->siteHost($input)));
		} catch (\Throwable $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}
		if (null !== $slug) {
			$plugins = array_values(array_filter($plugins, static fn (array $plugin): bool => ($plugin['slug'] ?? null) === $slug));
			if ([] === $plugins) {
				$io->error(sprintf('Plugin "%s" is not installed.', $slug));

				return self::FAILURE;
			}
		}

		if ([] === $plugins) {
			$io->warning('No plugins are installed.');

			return self::SUCCESS;
		}

		$updated = 0;
		foreach ($plugins as $plugin) {
			$candidateSlug = (string) ($plugin['slug'] ?? '');
			$currentVersion = (string) ($plugin['version'] ?? '');

			try {
				$response = $this->requestApi('POST', '/api/admin/plugins/update', [
					'slug' => $candidateSlug,
					'version' => $forcedVersion,
					'storeUrl' => $storeUrl,
					'dryRun' => $dryRun,
				], siteHost: $this->siteHost($input));
				$message = $this->operationMessage($response);
				$io->text($dryRun ? sprintf('%s: %s', $candidateSlug, $message) : $message);
				if (!$dryRun && is_array($response['result'] ?? null) && isset($response['result']['version'])) {
					++$updated;
				}
			} catch (\Throwable $exception) {
				$io->error(sprintf('%s: %s', $candidateSlug, $exception->getMessage()));

				return self::FAILURE;
			}
		}

		if (!$dryRun) {
			$io->success(sprintf('Updated %d plugin(s).', $updated));
		}

		return self::SUCCESS;
	}
}
