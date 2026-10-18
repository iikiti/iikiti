<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Batch;

use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\BatchMessageContext;
use iikiti\CMS\Batch\BatchMessageHandlerInterface;
use iikiti\CMS\Batch\BatchMessageHandlerRegistry;
use PHPUnit\Framework\TestCase;

final class BatchMessageHandlerRegistryTest extends TestCase
{
	public function testResolvesHandlersByStableTypeKey(): void
	{
		$handler = new class implements BatchMessageHandlerInterface {
			public function getType(): string { return 'text'; }
			public function handle(BatchMessage $message, BatchMessageContext $context): void {}
		};
		$registry = new BatchMessageHandlerRegistry([$handler]);

		self::assertSame($handler, $registry->get('text'));
		self::assertSame(['text'], $registry->getTypes());
	}

	public function testRejectsDuplicateHandlerTypes(): void
	{
		$createHandler = static fn (): BatchMessageHandlerInterface => new class implements BatchMessageHandlerInterface {
			public function getType(): string { return 'image'; }
			public function handle(BatchMessage $message, BatchMessageContext $context): void {}
		};

		$this->expectException(\LogicException::class);
		new BatchMessageHandlerRegistry([$createHandler(), $createHandler()]);
	}
}
