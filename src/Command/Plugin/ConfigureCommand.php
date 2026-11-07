<?php

namespace iikiti\CMS\Command\Plugin;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:plugin:configure',
	description: 'Read or update a plugin\'s per-site configuration.',
)]
class ConfigureCommand extends AbstractPluginCommand
{
	protected function configure(): void
	{
		$this->
			addArgument('slug', InputArgument::REQUIRED, 'The plugin slug to configure.')
			->addOption('site', null, InputOption::VALUE_REQUIRED, 'The site id to configure (required when setting values).')
			->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set a value as key=value. Repeatable.')
			->addOption('unset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Remove a configuration key. Repeatable.');
		$this->configureSiteHostOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = (string) $input->getArgument('slug');
		$siteId = $input->getOption('site');
		/** @var list<string> $set */
		$set = $input->getOption('set');
		/** @var list<string> $unset */
		$unset = $input->getOption('unset');
		$hasChanges = [] !== $set || [] !== $unset;

		if ($hasChanges && null === $siteId) {
			$io->error('The --site option is required when setting configuration.');

			return self::FAILURE;
		}

		try {
			$response = $this->requestApi(
				'POST',
				'/api/admin/plugins/configure',
				['slug' => $slug, 'siteId' => $siteId, 'set' => $set, 'unset' => $unset],
				siteHost: $this->siteHost($input),
			);
			$message = $this->operationMessage($response);
			if (!$hasChanges) {
				$io->text(sprintf('Configuration for "%s":', $slug));
				$io->writeln(json_encode($response['configuration'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

				return self::SUCCESS;
			}

			$io->success($message);
		} catch (\Throwable $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
