<?php

namespace iikiti\CMS\Filters;

use Dom\HTMLDocument;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * High-level class for output filters.
 */
class AbstractFilter
{
	private const HTML_DOCUMENT_ATTRIBUTE = '_iikiti_html_document';

	/**
	 * Check if string is empty and optionally trim whitespace (default is true).
	 */
	protected static function isEmptyString(string $str, bool $trim = true): bool
	{
		return !($trim ? isset(trim($str)[0]) : isset($str[0]));
	}

	/**
	 * Check if the provided Response object will be returning html content.
	 */
	protected static function isHtmlResponseType(Request $request, Response $response): bool
	{
		$htmlMime = $request->getMimeType('html') ?? 'text/html';
		$ctHeader = 'Content-Type';

		return false == $response->headers->has($ctHeader) ||
			str_starts_with($response->headers->get($ctHeader) ?? $htmlMime, $htmlMime);
	}

	protected static function getHtmlDocument(ResponseEvent $event): HTMLDocument
	{
		$request = $event->getRequest();
		$document = $request->attributes->get(self::HTML_DOCUMENT_ATTRIBUTE);
		if ($document instanceof HTMLDocument) {
			return $document;
		}

		$response = $event->getResponse();
		$document = $response instanceof DomHtmlResponse
			? $response->document()
			: HTMLDocument::createFromString((string) $response->getContent(), LIBXML_COMPACT | LIBXML_NOERROR);
		$request->attributes->set(self::HTML_DOCUMENT_ATTRIBUTE, $document);

		return $document;
	}

	protected static function saveHtmlDocument(ResponseEvent $event, HTMLDocument $document): void
	{
		$event->getRequest()->attributes->set(self::HTML_DOCUMENT_ATTRIBUTE, $document);
		$event->getResponse()->setContent($document->saveHTML($document));
	}
}
