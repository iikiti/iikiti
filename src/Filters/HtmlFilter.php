<?php

namespace iikiti\CMS\Filters;

use Dom\Document;
use Dom\HTMLDocument;
use Dom\XPath;
use DOMDocument;
use iikiti\CMS\Utility\Variable as V;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * HTML output filter. Parses HTML into a DOM structure for manipulation.
 */
class HtmlFilter extends AbstractFilter
{
	/**
	 * Only static methods, prevent creating instances.
	 */
	private function __construct()
	{
	}

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
			false == static::isHtmlResponseType($event->getRequest(), $event->getResponse())
		) {
			return;
		}
		$stopwatch->start('html_load');
		$html = (string) $event->getResponse()->getContent();
		$dom = HTMLDocument::createFromString(
			$html,
			LIBXML_COMPACT | LIBXML_NOERROR
		);
		$stopwatch->stop('html_load');
		$stopwatch->start('html_minify');
		self::minifyHtml($dom);
		$stopwatch->stop('html_minify');
		$stopwatch->start('html_save');
		$event->getResponse()->setContent($dom->saveHTML($dom));
		$stopwatch->stop('html_save');
	}

	protected static function minifyHtml(\DOMDocument|Document $dom): void
	{
		$xpath = $dom instanceof DOMDocument ?
			new \DOMXPath($dom) :
			new XPath($dom);
		$nodes = $xpath->query(
			'//text()[not(parent::script or parent::style or ancestor::pre)]'
		);
		/** @var \DOMText|\DOMComment $node */
		foreach ($nodes as $node) {
			if ($node instanceof \DOMComment) {
				$node->parentNode?->removeChild($node);
				continue;
			}
			$node->textContent = (string) preg_replace_callback(
				'/(?:[\h]{2,}|\v+)/u',
				function(...$args): string {
					return static::__collapseWhitespace_cb(...$args);
				},
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

		return (string) preg_replace(
			'/(?:\v+|(\h)\h+)/u', '$1', $text[0]
		);
	}
}
