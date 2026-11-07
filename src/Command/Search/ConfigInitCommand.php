<?php

namespace iikiti\CMS\Command\Search;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds default search index configurations.
 */
#[AsCommand('iikiti:search:config:init', description: 'Create default search index configurations')]
final class ConfigInitCommand extends AbstractSearchCommand
{
	protected function configure(): void
	{
		$this->addOption('site-host', null, InputOption::VALUE_REQUIRED, 'Use this site host as API context.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		try {
			$indexes = $this->getIndexes($input);
			$frontend = $this->findBySlug($indexes, 'frontend');
			if (null !== $frontend) {
				$io->warning('Default frontend search index already exists. Use --force to recreate.');

				$admin = $this->findBySlug($indexes, 'admin');
				if (null !== $admin && !($frontend['systemLocked'] ?? false)
					&& !$io->confirm('Recreate default indexes?', false)) {
					return Command::SUCCESS;
				}
			}

			$this->requestSearchAction('/api/admin/search/config/init', $input);
			$io->success('Default search configurations created successfully.');
		} catch (\Throwable $e) {
			$io->error('Failed to create default configurations: '.$e->getMessage());

			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

	/**
	 * @param list<array<string,mixed>> $indexes
	 * @return array<string,mixed>|null
	 */
	private function findBySlug(array $indexes, string $slug): ?array
	{
		foreach ($indexes as $index) {
			if (($index['slug'] ?? null) === $slug) {
				return $index;
			}
		}

		return null;
	}
}
