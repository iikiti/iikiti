<?php

namespace iikiti\CMS\Command\Plugin;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:plugin:remove',
	description: 'Uninstall a plugin and disable it for every site.',
)]
class RemoveCommand extends AbstractPluginCommand
{
	protected function configure(): void
	{
		$this->
			addArgument('slug', InputArgument::REQUIRED, 'The plugin slug to remove.')->
			addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask for confirmation.');
		$this->configureSiteHostOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = (string) $input->getArgument('slug');

		if (!$input->getOption('force') && !$io->confirm(sprintf('Remove plugin "%s" and all of its files?', $slug), false)) {
			$io->warning('Aborted.');

			return self::SUCCESS;
		}

		try {
			$response = $this->requestApi('POST', '/api/admin/plugins/remove', ['slug' => $slug], siteHost: $this->siteHost($input));
			$io->success($this->operationMessage($response));
		} catch (\Throwable $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
