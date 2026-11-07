<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security\Firewall;

use iikiti\CMS\Security\Firewall\FirewallInspectionException;
use iikiti\CMS\Security\Firewall\FirewallRequestContextFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class FirewallRequestContextFactoryTest extends TestCase
{
	public function testCapturesUrlFieldsJsonAndUploadedFileContents(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'firewall-upload-');
		self::assertNotFalse($path);
		file_put_contents($path, 'file payload');
		try {
			$request = Request::create(
				'/search?q=hello',
				'POST',
				['name' => 'Ada'],
				[],
				['document' => new UploadedFile($path, 'payload.txt', 'text/plain', null, true)],
				['HTTP_X_TEST' => 'header-value'],
			);
			$context = (new FirewallRequestContextFactory())->create($request, 4);

			self::assertSame(['/search?q=hello'], $context->getValues('uri'));
			self::assertSame(['hello'], $context->getValues('query', 'q'));
			self::assertSame(['Ada'], $context->getValues('field', 'name'));
			self::assertSame(['header-value'], $context->getValues('header', 'x-test'));
			self::assertSame(['file payload'], $context->getValues('upload', 'document'));
		} finally {
			@unlink($path);
		}
	}

	public function testRejectsRequestWhoseContentLengthExceedsInspectionLimit(): void
	{
		$request = Request::create('/', 'POST', [], [], [], ['CONTENT_LENGTH' => '1000'], 'payload');

		$this->expectException(FirewallInspectionException::class);
		(new FirewallRequestContextFactory(256))->create($request, 1);
	}

	public function testReplaysAStreamedBodyForTheControllerAfterInspection(): void
	{
		$request = Request::create('/submit', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"message":"hello"}');

		$context = (new FirewallRequestContextFactory())->create($request, 1);

		self::assertContains('{"message":"hello"}', $context->getValues('body'));
		self::assertContains('hello', $context->getValues('body'));
		self::assertSame('{"message":"hello"}', $request->getContent());
		self::assertSame(['hello'], $context->getValues('field', 'message'));
	}

	public function testRejectsAnUninspectableUpload(): void
	{
		$request = Request::create('/', 'POST', [], [], [
			'document' => new UploadedFile('/tmp/missing-firewall-upload', 'payload.txt', 'text/plain', \UPLOAD_ERR_PARTIAL),
		]);

		$this->expectException(FirewallInspectionException::class);
		(new FirewallRequestContextFactory())->create($request, 1);
	}
}
