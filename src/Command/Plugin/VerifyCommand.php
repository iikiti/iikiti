<?php

namespace iikiti\CMS\Command\Plugin;

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
	protected function configure(): void
	{
		$this->addArgument('slug', InputArgument::REQUIRED, 'The plugin slug to verify.');
		$this->configureSiteHostOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$slug = (string) $input->getArgument('slug');

		try {
			$response = $this->requestApi('POST', '/api/admin/plugins/verify', ['slug' => $slug], siteHost: $this->siteHost($input));
			$this->operationMessage($response);
			$results = is_array($response['result'] ?? null) ? $response['result'] : [];
			$checks = [];
			foreach ($results as $name => $valid) {
				$value = 'active' === $name && false === $valid
					? 'not active'
					: (is_bool($valid) ? (true === $valid ? 'ok' : 'failed') : (string) $valid);
				$checks[] = [ucfirst((string) $name), $value];
			}
			$io->table(['Check', 'Result'], $checks);
			$io->success(sprintf('Plugin "%s" verified.', $slug));
		} catch (\Throwable $exception) {
			$io->error($exception->getMessage());

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
