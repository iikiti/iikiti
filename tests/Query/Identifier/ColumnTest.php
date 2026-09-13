<?php

namespace iikiti\CMS\Tests\Query\Identifier;

use iikiti\CMS\Query\Exception\IdentifierValidationException;
use iikiti\CMS\Query\Identifier\Column;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
	public function testBareName(): void
	{
		$column = new Column('id');

		$this->assertSame('id', $column->getValue());
		$this->assertSame('id', (string) $column);
	}

	public function testQualifiedNameFromParts(): void
	{
		$column = new Column('id', 'u');

		$this->assertSame('u.id', (string) $column);
	}

	public function testQualifiedNameFromString(): void
	{
		$column = new Column('u.id');

		$this->assertSame('u.id', (string) $column);
	}

	public function testSchemaQualifiedName(): void
	{
		$this->assertSame('public.objects.id', (string) new Column('public.objects.id'));
	}

	public function testCarriesNoParameters(): void
	{
		$this->assertTrue((new Column('id'))->getParameters()->isEmpty());
	}

	#[DataProvider('invalidIdentifiers')]
	public function testRejectsInvalidIdentifiers(string $value): void
	{
		$this->expectException(IdentifierValidationException::class);

		new Column($value);
	}

	/**
	 * @return iterable<string,array{string}>
	 */
	public static function invalidIdentifiers(): iterable
	{
		yield 'empty' => [''];
		yield 'space' => ['id or 1=1'];
		yield 'semicolon' => ['id; DROP TABLE users'];
		yield 'quote' => ['"id"'];
		yield 'dash' => ['id-1'];
		yield 'trailing dot' => ['u.'];
		yield 'too many parts' => ['a.b.c.d'];
		yield 'leading digit' => ['1id'];
	}
}
