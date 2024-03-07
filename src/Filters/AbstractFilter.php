<?php

namespace iikiti\CMS\Filters;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * High-level class for output filters.
 */
class AbstractFilter
{
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
}
