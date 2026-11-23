<?php

namespace iikiti\CMS\Filters;

use Dom\Document;
use Dom\Node;
use iikiti\CMS\Utility\Variable as V;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * HTML output filter. Parses HTML into a DOM structure for manipulation.
 */
class HtmlFilter extends AbstractFilter
{
	/** @var array<string,true> */
	private const array PRESERVED_ELEMENTS = [
		'PRE' => true,
		'SCRIPT' => true,
		'STYLE' => true,
		'TEXTAREA' => true,
	];

	/**
	 * Only static methods, prevent creating instances.
	 */
	private function __construct()
	{
	}

	public static function filterHtml(
		ResponseEvent $event,
		?Stopwatch $stopwatch = null
	): void {
		$useHtmlFilter = V::is_true($_ENV['HTML_FILTER'] ?? true);
		$appEnv = $_ENV['APP_ENV'] ?? 'prod';
		if (
			('dev' === $appEnv && false == $useHtmlFilter) ||
			false === $useHtmlFilter ||
			false === $event->isMainRequest() ||
			false == static::isHtmlResponseType($event->getRequest(), $event->getResponse())
		) {
			return;
		}
		$stopwatch?->start('html_load');
		$dom = self::getHtmlDocument($event);
		$stopwatch?->stop('html_load');
		$stopwatch?->start('html_minify');
		self::minifyHtml($dom);
		$stopwatch?->stop('html_minify');
		$stopwatch?->start('html_save');
		self::saveHtmlDocument($event, $dom);
		$stopwatch?->stop('html_save');
	}

	protected static function minifyHtml(Document $dom): void
	{
		self::minifyNode($dom);
	}

	private static function minifyNode(Node $node): void
	{
		if (isset(self::PRESERVED_ELEMENTS[strtoupper($node->nodeName)])) {
			return;
		}

		$child = $node->firstChild;
		while (null !== $child) {
			// Advance before mutating so comment removal never skips the next sibling.
			$next = $child->nextSibling;
			$nodeType = $child->nodeType;
			if (XML_COMMENT_NODE === $nodeType) {
				$node->removeChild($child);
			} elseif (XML_TEXT_NODE === $nodeType) {
				$original = $child->textContent ?? '';
				if ('' !== $original) {
					$collapsed = (string) preg_replace_callback(
						'/(?:[\h]{2,}|\v+)/u',
						static fn (array $matches): string => static::__collapseWhitespace_cb($matches),
						$original,
					);
					if ($collapsed !== $original) {
						$child->textContent = $collapsed;
					}
				}
			} elseif (XML_ELEMENT_NODE === $nodeType) {
				self::minifyNode($child);
			}

			$child = $next;
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
			'/(?:\v+|(\h)\h+)/u',
			'$1',
			$text[0]
		);
	}
}
