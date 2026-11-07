<?php

namespace iikiti\CMS\Command\Plugin;

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
			$response = $this->requestApi('POST', '/api/admin/plugins/disable', ['slug' => $slug, 'sites' => $siteIds ?? []], siteHost: $this->siteHost($input));
			$io->success($this->operationMessage($response));
		} catch (\Throwable $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
