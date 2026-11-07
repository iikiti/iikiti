<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

use Dom\HTMLDocument;
use Symfony\Component\HttpFoundation\Response;

/** Response that retains its composed document for later DOM output filters. */
final class DomHtmlResponse extends Response
{
	/** @param array<string,string> $headers */
	public function __construct(
		private readonly HTMLDocument $document,
		int $status = self::HTTP_OK,
		array $headers = [],
	) {
		parent::__construct($document->saveHTML($document), $status, $headers);
		if (!$this->headers->has('Content-Type')) {
			$this->headers->set('Content-Type', 'text/html; charset=UTF-8');
		}
	}

	public function document(): HTMLDocument
	{
		return $this->document;
	}
}
