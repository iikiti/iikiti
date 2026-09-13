<?php

namespace iikiti\CMS\Command\Plugin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Plugin\Exception\PluginException;
use iikiti\CMS\Plugin\PluginContainerRebuilder;
use iikiti\CMS\Plugin\PluginDownloader;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginRegistry;
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
	public function __construct(
		PluginManager $pluginManager,
		PluginDownloader $pluginDownloader,
		PluginRegistry $pluginRegistry,
		PluginContainerRebuilder $containerRebuilder,
		private readonly EntityManagerInterface $entityManager,
	) {
		parent::__construct($pluginManager, $pluginDownloader, $pluginRegistry, $containerRebuilder);
	}

	protected function configure(): void
	{
		$this->
			addArgument('slug', InputArgument::REQUIRED, 'The plugin slug to configure.')->
			addOption('site', null, InputOption::VALUE_REQUIRED, 'The site id to configure (required when setting values).')->
			addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set a value as key=value. Repeatable.')->
			addOption('unset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Remove a configuration key. Repeatable.');
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

		if (!isset($this->pluginManager->getInstalled()[$slug])) {
			$io->error(sprintf('Plugin "%s" is not installed.', $slug));

			return self::FAILURE;
		}

		if ([] === $set && [] === $unset) {
			$io->text(sprintf('Configuration for "%s":', $slug));
			$io->writeln(json_encode($this->readConfig($slug, $siteId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

			return self::SUCCESS;
		}

		if (null === $siteId) {
			$io->error('The --site option is required when setting configuration.');

			return self::FAILURE;
		}

		try {
			$this->writeConfig($slug, (string) $siteId, $set, $unset);
		} catch (PluginException $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		$io->success(sprintf('Configuration updated for "%s" on site %s.', $slug, $siteId));

		return self::SUCCESS;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function readConfig(string $slug, ?string $siteId): array
	{
		if (null === $siteId) {
			return [];
		}

		foreach ($this->pluginManager->getSites() as $site) {
			if ((string) $site->getId() === $siteId) {
				return (array) $site->getConfiguration()->getPluginConfiguration($slug);
			}
		}

		return [];
	}

	/**
	 * @param list<string> $set
	 * @param list<string> $unset
	 */
	private function writeConfig(string $slug, string $siteId, array $set, array $unset): void
	{
		foreach ($this->pluginManager->getSites() as $site) {
			if ((string) $site->getId() !== $siteId) {
				continue;
			}

			$config = (array) $site->getConfiguration()->getPluginConfiguration($slug);
			foreach ($set as $pair) {
				if (!str_contains($pair, '=')) {
					throw new PluginException(sprintf('Invalid --set value "%s"; expected key=value.', $pair));
				}
				[$key, $value] = explode('=', $pair, 2);
				$config[$key] = $value;
			}
			foreach ($unset as $key) {
				unset($config[$key]);
			}

			$configuration = $site->getConfiguration();
			$configuration->setPluginConfiguration($slug, $config);
			$site->setConfiguration($configuration);
			$this->entityManager->flush();

			return;
		}

		throw new PluginException(sprintf('Site "%s" was not found.', $siteId));
	}
}
