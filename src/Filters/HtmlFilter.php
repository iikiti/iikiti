<?php

namespace iikiti\CMS\Filters;

use iikiti\CMS\Utility\Variable as V;
use IvoPetkov\HTML5DOMDocument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Class HtmlFilter.
 */
abstract class HtmlFilter
{
	public static function filterHtml(
		ResponseEvent $event,
		Stopwatch $stopwatch
	): void {
		$useHtmlFilter = V::is_true($_ENV['HTML_FILTER'] ?? true);
		$appEnv = $_ENV['APP_ENV'] ?? 'prod';
		if (
			('dev' === $appEnv && false == $useHtmlFilter) ||
			false == $useHtmlFilter ||
			false == $event->isMainRequest() ||
			false == static::isHtmlType($event->getRequest(), $event->getResponse())
		) {
			return;
		}
		$stopwatch->start('html_load');
		$dom = new HTML5DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML((string) $event->getResponse()->getContent(), LIBXML_NOBLANKS);
		$stopwatch->stop('html_load');
		$stopwatch->start('html_minify');
		self::minifyHtml($dom);
		$stopwatch->stop('html_minify');
		$stopwatch->start('html_save');
		$event->getResponse()->setContent($dom->saveHTML($dom));
		$stopwatch->stop('html_save');
	}

	protected static function minifyHtml(\DOMDocument $dom): void
	{
		$xpath = new \DOMXPath($dom);
		$nodes = $xpath->query(
			'//text()[not(parent::script or parent::style or ancestor::pre)]'
		);
		/** @var \DOMText|\DOMComment $node */
		foreach ($nodes as $node) {
			if ($node instanceof \DOMComment) {
				$node->parentNode?->removeChild($node);
				continue;
			}
			$node->textContent = preg_replace_callback(
				'/(?:[\h]{2,}|\v+)/u',
				\Closure::fromCallable([self::class, '__collapseWhitespace_cb']),
				$node->textContent
			);
		}
	}

	/**
	 * @param array<int,string> $text
	 */
	protected static function __collapseWhitespace_cb(array $text): string
	{
		if (!isset($text[0])) {
			return '';
		}

		return preg_replace('/(?:\v+|(\h)\h+)/u', '$1', $text[0]);
	}

	protected static function isEmptyString(string $str, bool $trim = true): bool
	{
		return !($trim ? isset(trim($str)[0]) : isset($str[0]));
	}

	protected static function isHtmlType(Request $request, Response $response): bool
	{
		$htmlMime = $request->getMimeType('html') ?? 'text/html';
		$ctHeader = 'Content-Type';

		return false == $response->headers->has($ctHeader) ||
			str_starts_with($response->headers->get($ctHeader) ?? $htmlMime, $htmlMime);
	}
}
