<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Response\Composition;

use iikiti\CMS\Response\Composition\JsonArrayComposer;
use PHPUnit\Framework\TestCase;

final class JsonArrayComposerTest extends TestCase
{
	public function testComposesListPiecesInDeclaredOrder(): void
	{
		$composer = new JsonArrayComposer();
		$composer->add([['id' => 2], ['id' => 3]], order: 20);
		$composer->add([['id' => 1]], order: 10);

		self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $composer->compose());
	}

	public function testRejectsAssociativePieces(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$piece = ['id' => 1];
		(new JsonArrayComposer())->add($piece);
	}
}
