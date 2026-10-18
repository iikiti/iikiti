<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Batch;

use iikiti\CMS\Batch\BatchMessage;
use iikiti\CMS\Batch\BatchMessageType;
use PHPUnit\Framework\TestCase;

final class BatchMessageTest extends TestCase
{
	public function testCreatesVersionedTypedEnvelopeWithDependencies(): void
	{
		$message = new BatchMessage('image-1', BatchMessageType::IMAGE, ['assetId' => 7, 'uri' => 'https://media.example/image.jpg'], 'Process image', 'image:7', ['text-1'], 2);

		self::assertSame(BatchMessageType::IMAGE, $message->type);
		self::assertSame(['text-1'], $message->dependsOn);
		self::assertSame(2, $message->version);
	}

	public function testRejectsInlinePayloadLargerThanTheBoundedMessageLimit(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('64 KiB');

		new BatchMessage('large', BatchMessageType::TEXT, ['content' => str_repeat('x', 65537)], 'Text', 'text:large');
	}

	public function testRejectsSelfDependencyAndDuplicateDependencies(): void
	{
		try {
			new BatchMessage('same', BatchMessageType::TEXT, [], 'Text', 'text:same', ['same']);
			self::fail('Self-dependency should be rejected.');
		} catch (\InvalidArgumentException $exception) {
			self::assertStringContainsString('cannot depend on itself', $exception->getMessage());
		}

		$this->expectException(\InvalidArgumentException::class);
		new BatchMessage('dependent', BatchMessageType::TEXT, [], 'Text', 'text:dependent', ['root', 'root']);
	}

	public function testRedactsCredentialLikeValuesFromDisplaySummary(): void
	{
		$message = new BatchMessage('item', BatchMessageType::TEXT, [], 'Refresh token=top-secret now', 'text:item');

		self::assertSame('Refresh token=[redacted] now', $message->summary);
		self::assertStringNotContainsString('top-secret', $message->summary);
	}
}
