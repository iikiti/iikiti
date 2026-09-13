<?php

namespace iikiti\CMS\Tests\Query\Identifier;

use iikiti\CMS\Query\Exception\IdentifierValidationException;
use iikiti\CMS\Query\Identifier\Alias;
use PHPUnit\Framework\TestCase;

final class AliasTest extends TestCase
{
	public function testSimpleAlias(): void
	{
		$this->assertSame('u', (string) new Alias('u'));
	}

	public function testRejectsQualifiedAlias(): void
	{
		$this->expectException(IdentifierValidationException::class);

		new Alias('u.x');
	}

	public function testRejectsInvalidAlias(): void
	{
		$this->expectException(IdentifierValidationException::class);

		new Alias('u as x');
	}
}
