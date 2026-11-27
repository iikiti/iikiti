<?php

namespace iikiti\CMS\Filters;

use Dom\HTMLDocument;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use Jimbo2150\CssIndexedDom\IndexedHtmlDocument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * High-level class for output filters.
 */
class AbstractFilter
{
	public const string DEFER_HTML_SAVE_ATTRIBUTE = '_iikiti_defer_html_save';

	private const string HTML_DOCUMENT_ATTRIBUTE = '_iikiti_html_document';
	private const string HTML_DOCUMENT_DIRTY_ATTRIBUTE = '_iikiti_html_document_dirty';

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

	protected static function getHtmlDocument(ResponseEvent $event): IndexedHtmlDocument
	{
		$request = $event->getRequest();
		$isDeferred = true === $request->attributes->get(self::DEFER_HTML_SAVE_ATTRIBUTE);
		if ($isDeferred) {
			$document = $request->attributes->get(self::HTML_DOCUMENT_ATTRIBUTE);
			if ($document instanceof IndexedHtmlDocument) {
				return $document;
			}
			if ($document instanceof HTMLDocument) {
				$indexed = IndexedHtmlDocument::fromDocument($document, lazyIndex: true);
				$request->attributes->set(self::HTML_DOCUMENT_ATTRIBUTE, $indexed);

				return $indexed;
			}
		}

		$response = $event->getResponse();
		$document = $response instanceof DomHtmlResponse && $response->isDocumentCurrent()
			? $response->indexedDocument()
			: IndexedHtmlDocument::createFromString((string) $response->getContent(), LIBXML_COMPACT | LIBXML_NOERROR, lazyIndex: true);
		if ($isDeferred) {
			$request->attributes->set(self::HTML_DOCUMENT_ATTRIBUTE, $document);
		}

		return $document;
	}

	protected static function saveHtmlDocument(ResponseEvent $event, IndexedHtmlDocument|HTMLDocument $document): void
	{
		$request = $event->getRequest();
		if (true === $request->attributes->get(self::DEFER_HTML_SAVE_ATTRIBUTE)) {
			if ($document instanceof HTMLDocument) {
				$existing = $request->attributes->get(self::HTML_DOCUMENT_ATTRIBUTE);
				if ($existing instanceof IndexedHtmlDocument && $existing->document() === $document) {
					// Raw DOM mutations outside the wrapper may invalidate its index buckets.
					$existing->invalidate();
					$document = $existing;
				} else {
					$document = IndexedHtmlDocument::fromDocument($document, lazyIndex: true);
				}
			}

			$request->attributes->set(self::HTML_DOCUMENT_ATTRIBUTE, $document);
			$request->attributes->set(self::HTML_DOCUMENT_DIRTY_ATTRIBUTE, true);
			$response = $event->getResponse();
			if ($response instanceof DomHtmlResponse) {
				$response->markDirty();
			}

			return;
		}

		$request->attributes->remove(self::HTML_DOCUMENT_DIRTY_ATTRIBUTE);
		$request->attributes->remove(self::HTML_DOCUMENT_ATTRIBUTE);
		$html = $document instanceof IndexedHtmlDocument
			? $document->saveHtml()
			: $document->saveHTML($document);
		$event->getResponse()->setContent($html);
	}

	/**
	 * Serializes the shared DOM document once after a deferred filter batch and
	 * clears the cached DOM reference so later listeners cannot reuse a stale tree.
	 */
	public static function flushHtmlDocument(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		$isDirty = true === $request->attributes->get(self::HTML_DOCUMENT_DIRTY_ATTRIBUTE);
		$document = $request->attributes->get(self::HTML_DOCUMENT_ATTRIBUTE);
		$request->attributes->remove(self::HTML_DOCUMENT_DIRTY_ATTRIBUTE);
		$request->attributes->remove(self::HTML_DOCUMENT_ATTRIBUTE);

		if ($isDirty && $document instanceof IndexedHtmlDocument) {
			$event->getResponse()->setContent($document->saveHtml());
		} elseif ($isDirty && $document instanceof HTMLDocument) {
			$event->getResponse()->setContent($document->saveHTML($document));
		}
	}
}
