<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Command\Security;

use iikiti\CMS\Command\Security\GeneratePasswordCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GeneratePasswordCommandTest extends TestCase
{
	public function testDefaultGeneratesStrongPasswordWithAllCharacterClasses(): void
	{
		$tester = new CommandTester(new GeneratePasswordCommand());

		self::assertSame(0, $tester->execute([]));
		$password = trim($tester->getDisplay());
		self::assertSame(32, strlen($password));
		self::assertMatchesRegularExpression('/[a-z]/', $password);
		self::assertMatchesRegularExpression('/[A-Z]/', $password);
		self::assertMatchesRegularExpression('/[0-9]/', $password);
		self::assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $password);
		self::assertSame($password."\n", $tester->getDisplay());
	}

	public function testStrengthPresetsChooseExpectedLengthsAndCharacterClasses(): void
	{
		$cases = [
			'weak' => [12, '/^[a-z0-9]+$/', ['/[a-z]/', '/[0-9]/']],
			'medium' => [20, '/^[a-zA-Z0-9]+$/', ['/[a-z]/', '/[A-Z]/', '/[0-9]/']],
			'strong' => [32, '/^[a-zA-Z0-9!#$%&()*+,\-.\/:;<=>?@\[\]\^_{}|~]+$/', ['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^a-zA-Z0-9]/']],
			'paranoid' => [64, '/^[a-zA-Z0-9!#$%&()*+,\-.\/:;<=>?@\[\]\^_{}|~]+$/', ['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^a-zA-Z0-9]/']],
		];

		foreach ($cases as $strength => [$expectedLength, $allowedCharacters, $requiredCharacterClasses]) {
			$tester = new CommandTester(new GeneratePasswordCommand());

			self::assertSame(0, $tester->execute(['--strength' => $strength]));
			$password = trim($tester->getDisplay());
			self::assertSame($expectedLength, strlen($password), $strength);
			self::assertMatchesRegularExpression($allowedCharacters, $password, $strength);
			foreach ($requiredCharacterClasses as $characterClass) {
				self::assertMatchesRegularExpression($characterClass, $password, $strength);
			}
		}
	}

	public function testLengthCanOverrideStrengthAndSymbolsCanBeDisabled(): void
	{
		$tester = new CommandTester(new GeneratePasswordCommand());

		self::assertSame(0, $tester->execute([
			'--strength' => 'strong',
			'--length' => '24',
			'--no-symbols' => true,
		]));
		$password = trim($tester->getDisplay());
		self::assertSame(24, strlen($password));
		self::assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $password);
		self::assertMatchesRegularExpression('/[a-z]/', $password);
		self::assertMatchesRegularExpression('/[A-Z]/', $password);
		self::assertMatchesRegularExpression('/[0-9]/', $password);
	}

	public function testRejectsUnknownStrengthAndTooShortPasswordsWithoutOutput(): void
	{
		foreach ([
			['--strength' => 'extreme'],
			['--strength' => 'medium', '--length' => '2'],
		] as $options) {
			$tester = new CommandTester(new GeneratePasswordCommand());

			self::assertSame(1, $tester->execute($options, ['capture_stderr_separately' => true]));
			self::assertSame('', $tester->getDisplay());
			self::assertNotSame('', $tester->getErrorOutput());
		}
	}

	public function testRejectsLengthOutsideSupportedRange(): void
	{
		$tester = new CommandTester(new GeneratePasswordCommand());

		self::assertSame(1, $tester->execute(['--length' => '257'], ['capture_stderr_separately' => true]));
		self::assertSame('', $tester->getDisplay());
	}
}
