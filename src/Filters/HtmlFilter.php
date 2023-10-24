<?php

namespace iikiti\CMS\Filters;

use Closure;
use DOMComment;
use DOMDocument;
use DOMText;
use DOMXPath;
use iikiti\CMS\Utility\Variable as V;
use IvoPetkov\HTML5DOMDocument;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Class HtmlFilter
 *
 * @package iikiti\CMS\src\Filters
 */
abstract class HtmlFilter {

    /**
     * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
     *
     * @return void
     */
    public static function filterHtml(ResponseEvent $event, Stopwatch $stopwatch): void {
		$useHtmlFilter = V::is_true($_ENV['HTML_FILTER'] ?? true);
		$appEnv = $_ENV['APP_ENV'] ?? 'prod';
        if(
            ($appEnv === 'dev' && !$useHtmlFilter) ||
            !$useHtmlFilter ||
            HttpKernelInterface::MAIN_REQUEST !== $event->getRequestType() ||
            !str_starts_with(
                (string) $event->getResponse()->headers->get('content-type'),
                'text/html'
            ) ||
            str_starts_with(
                (string) $event->getRequest()->attributes->get('_controller'),
                'web_profiler.'
            )
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

    /**
     * @param \DOMDocument $dom
     *
     * @return void
     */
    protected static function minifyHtml(DOMDocument $dom): void {
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query(
            '//text()[not(parent::script or parent::style or ancestor::pre)]'
        );
        /** @var \DOMText|\DOMComment $node */
        foreach ($nodes as $node) {
			if($node instanceof DOMComment) {
				$node->parentNode?->removeChild($node);
                continue;
			}
            $node->textContent = preg_replace_callback(
                '/(?:[\h]{2,}|\v+)/u',
                Closure::fromCallable([self::class, '__collapseWhitespace_cb']),
                $node->textContent
            );
        }
    }

	/**
	 * @param array<int,string> $text
	 */
	protected static function __collapseWhitespace_cb(array $text): string {
		if(!isset($text[0])) return '';
		return preg_replace('/(?:\v+|(\h)\h+)/u', '$1', $text[0]);
	}

    /**
     * @param string $str
     * @param bool   $trim
     *
     * @return bool
     */
    protected static function isEmptyString(string $str, bool $trim = true): bool
    {
        return !($trim ? isset(trim($str)[0]) : isset($str[0]));
    }

}
