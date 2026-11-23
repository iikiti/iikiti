<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

use Dom\HTMLDocument;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response that retains its composed document for later DOM output filters and
 * serializes HTML lazily so multiple DOM passes do not re-serialize per filter.
 */
final class DomHtmlResponse extends Response
{
	private bool $dirty = true;
	private bool $contentOverridden = false;

	/** @param array<string,string> $headers */
	public function __construct(
		private readonly HTMLDocument $document,
		int $status = self::HTTP_OK,
		array $headers = [],
	) {
		parent::__construct('', $status, $headers);
		$this->dirty = true;
		$this->contentOverridden = false;
		if (!$this->headers->has('Content-Type')) {
			$this->headers->set('Content-Type', 'text/html; charset=UTF-8');
		}
	}

	public function document(): HTMLDocument
	{
		return $this->document;
	}

	/**
	 * Returns false once raw string content has been written via setContent(),
	 * signaling that callers must parse getContent() instead of reusing document().
	 */
	public function isDocumentCurrent(): bool
	{
		return !$this->contentOverridden;
	}

	public function markDirty(): void
	{
		$this->dirty = true;
		$this->contentOverridden = false;
	}

	public function syncContent(): void
	{
		if (!$this->dirty) {
			return;
		}

		$this->dirty = false;
		$this->contentOverridden = false;
		parent::setContent($this->document->saveHTML($this->document));
	}

	#[\Override]
	public function getContent(): string|false
	{
		if ($this->dirty) {
			$this->syncContent();
		}

		return parent::getContent();
	}

	#[\Override]
	public function setContent(?string $content): static
	{
		$this->dirty = false;
		$this->contentOverridden = true;

		return parent::setContent($content);
	}

	#[\Override]
	public function sendContent(): static
	{
		if ($this->dirty) {
			$this->syncContent();
		}

		return parent::sendContent();
	}
}
