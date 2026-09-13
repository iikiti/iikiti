<?php

namespace iikiti\CMS\Command\Plugin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Plugin\PluginContainerRebuilder;
use iikiti\CMS\Plugin\PluginDownloader;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Service\Configuration;
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
	public function __construct(
		PluginManager $pluginManager,
		PluginDownloader $pluginDownloader,
		PluginRegistry $pluginRegistry,
		PluginContainerRebuilder $containerRebuilder,
		private readonly EntityManagerInterface $entityManager,
	) {
		parent::__construct($pluginManager, $pluginDownloader, $pluginRegistry, $containerRebuilder);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$migrated = 0;

		foreach ($this->pluginManager->getSites() as $site) {
			$configuration = $site->getConfiguration();
			$json = (array) $configuration->getJson();

			if (!isset($json['extensions']) || !is_array($json['extensions'])) {
				continue;
			}

			// Merge legacy extensions into plugins, giving plugins precedence
			// per key, so a partially-migrated site is not skipped.
			$plugins = isset($json['plugins']) && is_array($json['plugins']) ? $json['plugins'] : [];
			$json['plugins'] = array_replace($json['extensions'], $plugins);
			unset($json['extensions']);

			$site->setConfiguration(new Configuration($json));
			++$migrated;
		}

		if ($migrated > 0) {
			$this->entityManager->flush();
		}

		$io->success(sprintf('Migrated configuration for %d site(s).', $migrated));

		return self::SUCCESS;
	}
}
