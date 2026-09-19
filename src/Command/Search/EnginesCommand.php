<?php

namespace iikiti\CMS\Command\Search;

use iikiti\CMS\Search\Strategy\SearchEngineRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists available search engine adapters.
 */
#[AsCommand('iikiti:search:engines', description: 'List available search engine adapters')]
final class EnginesCommand extends Command
{
	public function __construct(
		private readonly SearchEngineRegistry $engineRegistry,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$engines = $this->engineRegistry->describe();
		$defaultEngine = $this->engineRegistry->getDefaultEngine();

		$table = new Table($output);
		$table->setHeaders(['Name', 'Label', 'Default']);
		$table->setRows(array_map(
			static fn (array $e): array => [
				$e['name'],
				$e['label'],
				$e['name'] === $defaultEngine ? '<info>Yes</info>' : '',
			],
			$engines
		));

		$table->render();

		$io->newLine();
		$io->comment(sprintf('Default engine: <info>%s</info>', $defaultEngine));

		return Command::SUCCESS;
	}
}
