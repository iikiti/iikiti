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
	name: 'iikiti:security:generate-password',
	description: 'Generate a secure random password.',
)]
final class GeneratePasswordCommand extends Command
{
	private const MAX_LENGTH = 256;
	private const CHARACTER_CLASSES = [
		'lowercase' => 'abcdefghijklmnopqrstuvwxyz',
		'uppercase' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
		'digits' => '0123456789',
		'symbols' => '!#$%&()*+,-./:;<=>?@[]^_{}|~',
	];

	/** @var array<string, array{length: int, classes: list<string>}> */
	private const STRENGTHS = [
		'weak' => ['length' => 12, 'classes' => ['lowercase', 'digits']],
		'medium' => ['length' => 20, 'classes' => ['lowercase', 'uppercase', 'digits']],
		'strong' => ['length' => 32, 'classes' => ['lowercase', 'uppercase', 'digits', 'symbols']],
		'paranoid' => ['length' => 64, 'classes' => ['lowercase', 'uppercase', 'digits', 'symbols']],
	];

	protected function configure(): void
	{
		$this->
			addOption('strength', null, InputOption::VALUE_REQUIRED, 'Password preset: weak, medium, strong, or paranoid.', 'strong')->
			addOption('length', null, InputOption::VALUE_REQUIRED, 'Override the preset length (minimum one character per selected class; maximum 256).')->
			addOption('no-symbols', null, InputOption::VALUE_NONE, 'Exclude symbols from the selected character classes.')->
			setHelp('Generates a password using PHP\'s cryptographically secure random number generator. The default strong preset generates 32 characters.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$strength = $input->getOption('strength');
		if (!is_string($strength) || !isset(self::STRENGTHS[$strength])) {
			$this->writeError($output, 'Strength must be one of: weak, medium, strong, paranoid.');

			return Command::FAILURE;
		}

		$classes = self::STRENGTHS[$strength]['classes'];
		if (true === $input->getOption('no-symbols')) {
			$classes = array_filter($classes, static fn (string $class): bool => 'symbols' !== $class);
		}

		$requestedLength = $input->getOption('length');
		$length = null === $requestedLength ?
			self::STRENGTHS[$strength]['length'] :
			filter_var($requestedLength, FILTER_VALIDATE_INT);

		if (!is_int($length) || $length < count($classes) || $length > self::MAX_LENGTH) {
			$this->writeError($output, sprintf('Length must be an integer between %d and %d for the selected character classes.', count($classes), self::MAX_LENGTH));

			return Command::FAILURE;
		}

		try {
			$password = $this->generatePassword($length, $classes);
		} catch (\Throwable) {
			$this->writeError($output, 'Password generation failed.');

			return Command::FAILURE;
		}

		$output->writeln($password);

		return Command::SUCCESS;
	}

	/** @param list<string> $classes */
	private function generatePassword(int $length, array $classes): string
	{
		$characters = [];
		$alphabet = '';
		foreach ($classes as $class) {
			$classCharacters = self::CHARACTER_CLASSES[$class];
			$alphabet .= $classCharacters;
			$characters[] = $classCharacters[random_int(0, strlen($classCharacters) - 1)];
		}

		while (count($characters) < $length) {
			$characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
		}

		for ($index = count($characters) - 1; $index > 0; --$index) {
			$swapIndex = random_int(0, $index);
			[$characters[$index], $characters[$swapIndex]] = [$characters[$swapIndex], $characters[$index]];
		}

		return implode('', $characters);
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
