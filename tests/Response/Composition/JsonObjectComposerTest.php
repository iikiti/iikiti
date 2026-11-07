<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Response\Composition;

use iikiti\CMS\Response\Composition\JsonObjectComposer;
use LogicException;
use PHPUnit\Framework\TestCase;

final class JsonObjectComposerTest extends TestCase
{
	public function testComposesNestedPiecesInDeclaredOrder(): void
	{
		$composer = new JsonObjectComposer();
		$composer->add(['context' => ['type' => 'page'], 'plugins' => []], order: 20);
		$composer->add(['canEdit' => true, 'apiBase' => '/api'], order: 10);

		self::assertSame([
			'canEdit' => true,
			'apiBase' => '/api',
			'context' => ['type' => 'page'],
			'plugins' => [],
		], $composer->compose());
	}

	public function testRejectsDuplicateKeys(): void
	{
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('JSON object key "shared" was contributed more than once');

		$composer = new JsonObjectComposer();
		$composer->add(['shared' => 1]);
		$composer->add(['shared' => 2]);
		$composer->compose();
	}
}
