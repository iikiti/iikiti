<?php

namespace iikiti\CMS\Tests\Query\Identifier;

use iikiti\CMS\Query\Exception\IdentifierValidationException;
use iikiti\CMS\Query\Identifier\Table;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
	public function testBareName(): void
	{
		$this->assertSame('objects', (string) new Table('objects'));
	}

	public function testSchemaQualified(): void
	{
		$this->assertSame('public.objects', (string) new Table('objects', 'public'));
	}

	public function testRejectsInvalidName(): void
	{
		$this->expectException(IdentifierValidationException::class);

		new Table('objects; DROP TABLE users');
	}
}
