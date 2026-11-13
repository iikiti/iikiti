<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Command\Security;

use iikiti\CMS\Command\Security\GenerateKeyCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateKeyCommandTest extends TestCase
{
	public function testDefaultOutputsBase64EncodedThirtyTwoRandomBytesOnly(): void
	{
		$tester = new CommandTester(new GenerateKeyCommand());

		self::assertSame(0, $tester->execute([]));
		self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+=*\n$/', $tester->getDisplay());
		$keyBytes = base64_decode(trim($tester->getDisplay()), true);
		self::assertIsString($keyBytes);
		self::assertSame(32, strlen($keyBytes));
	}

	public function testSupportsConfiguredByteLength(): void
	{
		$tester = new CommandTester(new GenerateKeyCommand());

		self::assertSame(0, $tester->execute(['--bytes' => '64']));
		$keyBytes = base64_decode(trim($tester->getDisplay()), true);
		self::assertIsString($keyBytes);
		self::assertSame(64, strlen($keyBytes));
	}

	public function testRejectsByteLengthsOutsideSupportedRangeWithoutOutput(): void
	{
		foreach (['15', '1025', 'invalid'] as $byteLength) {
			$tester = new CommandTester(new GenerateKeyCommand());

			self::assertSame(1, $tester->execute(['--bytes' => $byteLength], ['capture_stderr_separately' => true]));
			self::assertSame('', $tester->getDisplay());
			self::assertNotSame('', $tester->getErrorOutput());
		}
	}
}
