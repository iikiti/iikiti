<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Asset;

use iikiti\CMS\DigitalAsset\Usage\AssetUsageIndexInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:asset:usage:rebuild',
	description: 'Rebuild the digital asset usage ("Where Used") index across templates, pages, sites, and applications.',
)]
final class RebuildAssetUsageIndexCommand extends Command
{
	public function __construct(
		private readonly AssetUsageIndexInterface $usageIndex,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$count = $this->usageIndex->rebuildAll();
		$io->success(sprintf('Rebuilt digital asset usage index across %d context(s).', $count));

		return Command::SUCCESS;
	}
}
