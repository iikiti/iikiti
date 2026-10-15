<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Workflow;

use iikiti\CMS\Workflow\Definition\WorkflowDefinitionSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'iikiti:workflow:sync-definitions', description: 'Synchronize active plugin workflow definitions into immutable database versions.')]
final class SyncWorkflowDefinitionsCommand extends Command
{
	public function __construct(private readonly WorkflowDefinitionSynchronizer $synchronizer)
	{
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$changed = $this->synchronizer->synchronizePluginDefinitions();
		$io->success(sprintf('Synchronized plugin workflow definitions; %d definition version(s) created.', $changed));

		return Command::SUCCESS;
	}
}
