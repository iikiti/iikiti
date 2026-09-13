<?php

namespace iikiti\CMS\Command\Plugin;

use iikiti\CMS\Plugin\PluginAutoloader;
use iikiti\CMS\Plugin\PluginContainerRebuilder;
use iikiti\CMS\Plugin\PluginDownloader;
use iikiti\CMS\Plugin\PluginLoader;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Plugin\PluginValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
	name: 'iikiti:plugin:verify',
	description: 'Verify an installed plugin\'s manifest, bundle class and activation link.',
)]
class VerifyCommand extends AbstractPluginCommand
{
	public function __construct(
		PluginManager $pluginManager,
		PluginDownloader $pluginDownloader,
		PluginRegistry $pluginRegistry,
		PluginContainerRebuilder $containerRebuilder,
		private readonly PluginValidator $validator,
		private readonly PluginLoader $loader,
	) {
		parent::__construct($pluginManager, $pluginDownloader, $pluginRegistry, $containerRebuilder);
	}

	protected function configure(): void
	{
		$this->addArgument('slug', InputArgument::REQUIRED, 'The plugin slug to verify.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = (string) $input->getArgument('slug');

		$installed = $this->pluginManager->getInstalled();
		if (!isset($installed[$slug])) {
			$io->error(sprintf('Plugin "%s" is not installed.', $slug));

			return self::FAILURE;
		}

		$plugin = $installed[$slug];
		$manifest = $plugin['manifest'];
		$checks = [];
		$valid = true;

		try {
			$this->validator->validateManifest($manifest, $plugin['installInfo']?->source->isTrusted() ?? false);
			$checks[] = ['Manifest', 'ok'];
		} catch (\Throwable $exception) {
			$valid = false;
			$checks[] = ['Manifest', $exception->getMessage()];
		}

		try {
			PluginAutoloader::registerPlugin($manifest, $plugin['path']);
			$this->validator->validateBundleClass($manifest, $plugin['path']);
			$checks[] = ['Bundle class', 'ok'];
		} catch (\Throwable $exception) {
			$valid = false;
			$checks[] = ['Bundle class', $exception->getMessage()];
		}

		$linkPath = $this->loader->getActivePath().'/'.$slug;
		if (is_link($linkPath)) {
			try {
				$target = $this->validator->resolveSymlinkTarget($linkPath, $this->loader->getInstalledPath());
				$checks[] = ['Activation link', 'ok -> '.$target];
			} catch (\Throwable $exception) {
				$valid = false;
				$checks[] = ['Activation link', $exception->getMessage()];
			}
		} else {
			$checks[] = ['Activation link', 'not active'];
		}

		$installInfo = $plugin['installInfo'];
		$checks[] = ['Checksum', null !== $installInfo ? ($installInfo->checksum ?? 'not recorded') : 'not recorded'];
		$checks[] = ['Signature', null !== $installInfo ? ($installInfo->signature ?? 'not recorded') : 'not recorded'];

		$io->table(['Check', 'Result'], $checks);

		if (!$valid) {
			$io->error(sprintf('Plugin "%s" failed verification.', $slug));

			return self::FAILURE;
		}

		$io->success(sprintf('Plugin "%s" verified.', $slug));

		return self::SUCCESS;
	}
}
