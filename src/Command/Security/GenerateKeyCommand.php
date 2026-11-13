<?php

declare(strict_types=1);

namespace iikiti\CMS\Command\Security;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
	name: 'iikiti:security:generate-key',
	description: 'Generate secure random key material encoded as base64.',
)]
final class GenerateKeyCommand extends Command
{
	private const MIN_BYTES = 16;
	private const MAX_BYTES = 1024;
	private const DEFAULT_BYTES = 32;

	protected function configure(): void
	{
		$this->
			addOption('bytes', null, InputOption::VALUE_REQUIRED, 'Number of random bytes to generate (16–1024).', (string) self::DEFAULT_BYTES)->
			setHelp('Generates random bytes and prints standard base64. The default is 32 bytes; use a size appropriate for the key\'s intended purpose.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$byteLength = filter_var($input->getOption('bytes'), FILTER_VALIDATE_INT);
		if (!is_int($byteLength) || $byteLength < self::MIN_BYTES || $byteLength > self::MAX_BYTES) {
			$this->writeError($output, sprintf('Byte length must be an integer between %d and %d.', self::MIN_BYTES, self::MAX_BYTES));

			return Command::FAILURE;
		}

		try {
			$key = base64_encode(random_bytes($byteLength));
		} catch (\Throwable) {
			$this->writeError($output, 'Key generation failed.');

			return Command::FAILURE;
		}

		$output->writeln($key);

		return Command::SUCCESS;
	}

	private function writeError(OutputInterface $output, string $message): void
	{
		if ($output instanceof ConsoleOutputInterface) {
			$output->getErrorOutput()->writeln('<error>'.$message.'</error>');

			return;
		}

		$output->writeln('<error>'.$message.'</error>');
	}
}
