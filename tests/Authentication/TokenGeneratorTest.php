<?php

namespace iikiti\CMS\Tests\Authentication;

use iikiti\CMS\Authentication\TokenGenerator\IntegerTokenGenerator;
use iikiti\CMS\Authentication\TokenGenerator\StringTokenGenerator;
use PHPUnit\Framework\TestCase;

final class TokenGeneratorTest extends TestCase
{
	public function testIntegerGeneratorRespectsBounds(): void
	{
		$generator = new IntegerTokenGenerator();

		$this->assertSame(5, $generator->generate(['min' => 5, 'max' => 5]));
	}

	public function testStringGeneratorProducesRequestedLength(): void
	{
		$generator = new StringTokenGenerator();

		$token = $generator->generate(['min' => 0, 'max' => 9, 'length' => 6]);

		$this->assertMatchesRegularExpression('/^[0-9]{6}$/', $token);
	}

	public function testStringGeneratorValidatesHashedToken(): void
	{
		$generator = new StringTokenGenerator();
		$token = $generator->generate(['min' => 0, 'max' => 9, 'length' => 6]);
		$hash = password_hash($token, PASSWORD_DEFAULT);

		$this->assertCount(0, $generator->validate($hash, $token));
		$this->assertGreaterThan(0, $generator->validate($hash, '000000' === $token ? '111111' : '000000')->count());
	}
}
