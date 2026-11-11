<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Locale;

use iikiti\CMS\Service\LocalizedPropertyIndexUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:locale-index:rebuild',
	description: 'Rebuild indexed localized object properties and their ICU locale indexes.',
)]
final class RebuildLocalizedPropertyIndexCommand extends Command
{
	public function __construct(
		private readonly LocalizedPropertyIndexUpdater $updater,
	) {
		parent::__construct();
	}

	protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$updated = $this->updater->rebuildAll();

		$io->success(sprintf('Rebuilt localized search/sort projections for %d object properties.', $updated));

		return Command::SUCCESS;
	}
}
