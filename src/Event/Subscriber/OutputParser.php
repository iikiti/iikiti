<?php

namespace iikiti\CMS\Event\Subscriber;

use iikiti\CMS\Filters\AbstractFilter;
use iikiti\CMS\Filters\HtmlFilter;
use iikiti\CMS\Filters\InlineCssFilter;
use iikiti\CMS\Filters\InlineJavaScriptFilter;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Handles core DOM output filters (single-pass serialization) and custom
 * plugin output filters registered via {@see appendFilter()}.
 */
class OutputParser implements EventSubscriberInterface
{
	/** @var array<int,mixed> */
	protected static array $filters = [];

	public function __construct(
		private readonly ?Stopwatch $stopwatch = null,
		private readonly ?InlineCssFilter $inlineCssFilter = null,
		private readonly ?InlineJavaScriptFilter $inlineJavaScriptFilter = null,
	) {
	}

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
	 * Appends a custom output filter closure.
	 */
	public static function appendFilter(\Closure $filter): void
	{
		self::$filters[] = $filter;
	}

	/**
	 * Fired on kernel response. Runs the core DOM filters in a single deferred
	 * serialization pass, flushes once, then executes any custom filters.
	 */
	public function onKernelResponse(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		$request->attributes->set(AbstractFilter::DEFER_HTML_SAVE_ATTRIBUTE, true);
		try {
			HtmlFilter::filterHtml($event, $this->stopwatch);
			$this->inlineCssFilter?->filter($event);
			$this->inlineJavaScriptFilter?->filter($event);
		} finally {
			$request->attributes->remove(AbstractFilter::DEFER_HTML_SAVE_ATTRIBUTE);
			AbstractFilter::flushHtmlDocument($event);
		}

		foreach (static::$filters as $filter) {
			if (!($filter instanceof \Closure)) {
				continue;
			}

			$filter($event);
		}
	}
}
