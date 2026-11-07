<?php

namespace iikiti\CMS\Command\Plugin;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:plugin:migrate',
	description: 'Migrate legacy "extensions" configuration keys to the "plugins" key.',
)]
class MigrateCommand extends AbstractPluginCommand
{
	protected function configure(): void
	{
		$this->configureSiteHostOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		try {
			$response = $this->requestApi('POST', '/api/admin/plugins/migrate-configuration', [], siteHost: $this->siteHost($input));
			$this->operationMessage($response);
			$result = is_array($response['result'] ?? null) ? $response['result'] : [];
			$count = (int) ($result['migratedSites'] ?? 0);
			$io->success(sprintf('Migrated configuration for %d site(s).', $count));
		} catch (\Throwable $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
