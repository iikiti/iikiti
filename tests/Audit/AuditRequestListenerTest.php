<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Audit;

use iikiti\CMS\Audit\AuditRequestListener;
use iikiti\CMS\Audit\AuditLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AuditRequestListenerTest extends TestCase
{
	/** @return iterable<string,array{string,bool}> */
	public static function methods(): iterable
	{
		yield 'GET is navigation' => ['GET', false];
		yield 'HEAD is navigation' => ['HEAD', false];
		yield 'OPTIONS is navigation' => ['OPTIONS', false];
		yield 'POST changes state' => ['POST', true];
		yield 'DELETE changes state' => ['DELETE', true];
	}

	#[DataProvider('methods')]
	public function testOnlyStateChangingMethodsOpenAnEvent(string $method, bool $opens): void
	{
		$logger = $this->createMock(AuditLogger::class);
		$logger->expects($opens ? self::once() : self::never())
			->method('openEvent')
			->with('request', self::anything());

		$request = Request::create('/admin/thing', $method);
		$event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

		(new AuditRequestListener($logger))->onRequest($event);
	}
}
