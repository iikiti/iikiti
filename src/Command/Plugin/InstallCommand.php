<?php

namespace iikiti\CMS\Command\Plugin;

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
			$io->text(sprintf('Installing "%s"...', $slug));
			$response = $this->requestApi('POST', '/api/admin/plugins/install', [
				'slug' => $slug,
				'version' => $version,
				'sites' => $siteIds ?? [],
				'storeUrl' => $storeUrl,
				'activate' => $activate,
			], siteHost: $this->siteHost($input));
			$io->success($this->operationMessage($response));
		} catch (\Throwable $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
