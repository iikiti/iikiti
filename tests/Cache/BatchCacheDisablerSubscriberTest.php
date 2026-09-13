<?php

namespace iikiti\CMS\Tests\Cache;

use iikiti\CMS\Event\Subscriber\BatchCacheDisablerSubscriber;
use iikiti\CMS\Service\CacheState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class BatchCacheDisablerSubscriberTest extends TestCase
{
	private function createEvent(Request $request): RequestEvent
	{
		/** @var HttpKernelInterface&\PHPUnit\Framework\MockObject\Stub $kernel */
		$kernel = $this->createStub(HttpKernelInterface::class);

		return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
	}

	public function testNoDisableWithoutBatchAttributes(): void
	{
		$state = new CacheState();
		$subscriber = new BatchCacheDisablerSubscriber($state, 'batch_');

		$subscriber->onKernelRequest($this->createEvent(Request::create('/api/sites')));

		$this->assertTrue($state->isEnabled());
	}

	public function testDisablesWhenRequestAttributeSet(): void
	{
		$state = new CacheState();
		$subscriber = new BatchCacheDisablerSubscriber($state, 'batch_');

		$request = Request::create('/api/batch/import');
		$request->attributes->set('_disable_db_cache', true);
		$subscriber->onKernelRequest($this->createEvent($request));

		$this->assertFalse($state->isEnabled());
	}

	public function testDisablesWhenRouteMatchesBatchPattern(): void
	{
		$state = new CacheState();
		$subscriber = new BatchCacheDisablerSubscriber($state, 'batch_');

		$request = Request::create('/api/batch/reindex');
		$request->attributes->set('_route', 'batch_reindex');
		$subscriber->onKernelRequest($this->createEvent($request));

		$this->assertFalse($state->isEnabled());
	}

	public function testDoesNotDisableForOtherRoutes(): void
	{
		$state = new CacheState();
		$subscriber = new BatchCacheDisablerSubscriber($state, 'batch_');

		$request = Request::create('/api/sites');
		$request->attributes->set('_route', 'api_sites_get_collection');
		$subscriber->onKernelRequest($this->createEvent($request));

		$this->assertTrue($state->isEnabled());
	}

	public function testStrategyOverrideFromRequestAttribute(): void
	{
		$state = new CacheState();
		$subscriber = new BatchCacheDisablerSubscriber($state, 'batch_');

		$request = Request::create('/api/sites');
		$request->attributes->set('_cache_strategy', 'none');
		$subscriber->onKernelRequest($this->createEvent($request));

		$this->assertSame('none', $state->getOverrideStrategy());
	}

	public function testSubRequestIsIgnored(): void
	{
		$state = new CacheState();

		/** @var HttpKernelInterface&\PHPUnit\Framework\MockObject\Stub $kernel */
		$kernel = $this->createStub(HttpKernelInterface::class);
		$subRequest = new Request();
		$subRequest->attributes->set('_disable_db_cache', true);
		$event = new RequestEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST);

		(new BatchCacheDisablerSubscriber($state, 'batch_'))->onKernelRequest($event);

		$this->assertTrue($state->isEnabled());
	}
}
