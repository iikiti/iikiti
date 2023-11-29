<?php

namespace iikiti\CMS\Event\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Class OutputParser.
 */
class OutputParser implements EventSubscriberInterface
{
	protected static array $filters = [];

	public static function getSubscribedEvents(): array
	{
		return [
			'kernel.response' => 'onKernelResponse',
		];
	}

	/**
	 * @return void
	 */
	public static function appendFilter(\Closure $filter)
	{
		self::$filters[] = $filter;
	}

	/**
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
