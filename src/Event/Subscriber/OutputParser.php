<?php

namespace iikiti\CMS\Event\Subscriber;

use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Handles output filters.
 */
class OutputParser implements EventSubscriberInterface
{
	protected static array $filters = [];

	/**
	 * Identifies subscribed events.
	 */
	#[Override]
	public static function getSubscribedEvents(): array
	{
		return [
			'kernel.response' => 'onKernelResponse',
		];
	}

	/**
	 * Appends a new filter.
	 *
	 * @return void
	 */
	public static function appendFilter(\Closure $filter)
	{
		self::$filters[] = $filter;
	}

	/**
	 * Fired on kernel response. Calls each assigned filter on the output.
	 *
	 * @return void
	 */
	public function onKernelResponse(ResponseEvent $event)
	{
		foreach (static::$filters as $filter) {
			if (!($filter instanceof \Closure)) {
				continue;
			}
			$filter($event);
		}
	}
}
