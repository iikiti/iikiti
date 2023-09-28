<?php

namespace iikiti\CMS\Filters;

use DOMDocument;
use DOMXPath;
use iikiti\CMS\Utility\Variable as V;
use IvoPetkov\HTML5DOMDocument;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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
    public static function filterHtml(ResponseEvent $event): void {
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
        $dom = new HTML5DOMDocument();
        $dom->loadHTML((string) $event->getResponse()->getContent());
        self::minifyHtml($dom);
        $event->getResponse()->setContent($dom->saveHTML($dom));
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
            if ($node->parentNode && static::isEmptyString($node->textContent)) {
                $node->parentNode->removeChild($node);
                continue;
            }
            $node->textContent = preg_replace(
                '/^\s{2,}|\s{2,}$/',
                ' ',
                $node->textContent
            );
        }
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
