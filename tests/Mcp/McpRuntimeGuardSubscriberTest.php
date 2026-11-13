<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Mcp;

use iikiti\CMS\Mcp\McpRuntimeGuardSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class McpRuntimeGuardSubscriberTest extends TestCase
{
	public function testConsumerRuntimeHidesTheMcpEndpoint(): void
	{
		$kernel = $this->createStub(HttpKernelInterface::class);
		$event = new RequestEvent($kernel, Request::create('/api/mcp'), HttpKernelInterface::MAIN_REQUEST);
		$subscriber = new McpRuntimeGuardSubscriber('consumer', true, 1_048_576);

		$this->expectException(NotFoundHttpException::class);
		$subscriber($event);
	}

	public function testApiRuntimeLeavesTheMcpEndpointAvailable(): void
	{
		$kernel = $this->createStub(HttpKernelInterface::class);
		$event = new RequestEvent($kernel, Request::create('/api/mcp'), HttpKernelInterface::MAIN_REQUEST);
		$subscriber = new McpRuntimeGuardSubscriber('api', true, 1_048_576);

		$subscriber($event);
		self::assertTrue($event->isMainRequest());
	}

	public function testApiRuntimeRejectsOversizedMcpRequests(): void
	{
		$kernel = $this->createStub(HttpKernelInterface::class);
		$request = Request::create('/api/mcp', 'POST');
		$request->headers->set('Content-Length', '100');
		$event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
		$subscriber = new McpRuntimeGuardSubscriber('api', true, 50);

		$this->expectException(HttpException::class);
		$subscriber($event);
	}

	public function testDisabledMcpTransportReturnsNotFound(): void
	{
		$kernel = $this->createStub(HttpKernelInterface::class);
		$event = new RequestEvent($kernel, Request::create('/api/mcp'), HttpKernelInterface::MAIN_REQUEST);
		$subscriber = new McpRuntimeGuardSubscriber('api', false, 1_048_576);

		$this->expectException(NotFoundHttpException::class);
		$subscriber($event);
	}
}
