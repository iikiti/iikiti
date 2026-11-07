<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

use Dom\DocumentFragment;
use Dom\HTMLDocument;
use RuntimeException;

/** Inserts trusted rendered HTML fragments at explicit placeholders. */
final class HtmlFragmentComposer
{
	/** @var list<array{selector:string,html:string|DocumentFragment,order:int,sequence:int}> */
	private array $fragments = [];
	private int $sequence = 0;
	private readonly HTMLDocument $document;

	public function __construct(string|HTMLDocument $documentHtml)
	{
		$this->document = $documentHtml instanceof HTMLDocument
			? $documentHtml
			: HTMLDocument::createFromString($documentHtml, LIBXML_COMPACT | LIBXML_NOERROR);
	}

	public function insertBefore(string $selector, string|DocumentFragment $html, int $order = 0): void
	{
		if ('' === trim($selector)) {
			throw new \InvalidArgumentException('A fragment insertion selector is required.');
		}

		$this->fragments[] = [
			'selector' => $selector,
			'html' => $html,
			'order' => $order,
			'sequence' => $this->sequence++,
		];
	}

	public function insertIntoPlaceholder(string $selector, string|DocumentFragment $html): void
	{
		$this->flush();
		$this->replaceNode($selector, $html);
	}

	public function insertFragmentIntoPlaceholder(string $selector, DocumentFragment $fragment): void
	{
		$this->flush();
		$placeholder = $this->document->querySelector($selector);
		if (null === $placeholder) {
			throw new RuntimeException(sprintf('HTML fragment placeholder "%s" was not found.', $selector));
		}

		$this->insertFragmentForElement($placeholder, $fragment);
	}

	public function createFragment(string $html): DocumentFragment
	{
		$this->flush();

		return $this->parseFragment($html);
	}

	public function hasPlaceholder(string $selector): bool
	{
		return null !== $this->document->querySelector($selector);
	}

	/** @param array<string,string> $embeddedHtml async embed token => HTML */
	public function replaceDeferredEmbeds(array $embeddedHtml): void
	{
		$this->flush();
		$placeholders = $this->document->querySelectorAll('template[data-iikiti-async-embed]');
		foreach ($placeholders as $placeholder) {
			$token = $placeholder->getAttribute('data-iikiti-async-embed');
			$this->replaceNodeForElement($placeholder, $embeddedHtml[$token] ?? '');
		}
	}

	public function document(): HTMLDocument
	{
		$this->flush();

		return $this->document;
	}

	public function render(): string
	{
		$this->flush();

		return $this->document->saveHTML($this->document);
	}

	private function flush(): void
	{
		$grouped = [];
		foreach ($this->fragments as $fragment) {
			$grouped[$fragment['selector']][] = $fragment;
		}
		$this->fragments = [];

		foreach ($grouped as $selector => $fragments) {
			$placeholder = $this->document->querySelector($selector);
			$parent = $placeholder?->parentNode;
			if (null === $placeholder || null === $parent) {
				throw new RuntimeException(sprintf('HTML fragment placeholder "%s" was not found.', $selector));
			}

			usort($fragments, static fn (array $left, array $right): int =>
				$left['order'] <=> $right['order'] ?: $left['sequence'] <=> $right['sequence']);
			$combined = $this->document->createDocumentFragment();
			foreach ($fragments as $fragment) {
				$combined->append($this->fragmentForDocument($fragment['html'], $parent->nodeName));
			}

			$parent->insertBefore($combined, $placeholder);
			$parent->removeChild($placeholder);
		}
	}

	private function replaceNode(string $selector, string|DocumentFragment $html): void
	{
		$placeholder = $this->document->querySelector($selector);
		if (null === $placeholder) {
			throw new RuntimeException(sprintf('HTML fragment placeholder "%s" was not found.', $selector));
		}

		$this->replaceNodeForElement($placeholder, $html);
	}

	private function replaceNodeForElement(\Dom\Element $placeholder, string|DocumentFragment $html): void
	{
		$parent = $placeholder->parentNode;
		if (null === $parent) {
			throw new RuntimeException('HTML fragment placeholder has no parent.');
		}

		$this->insertFragmentForElement($placeholder, $this->fragmentForDocument($html, $parent->nodeName));
	}

	private function fragmentForDocument(string|DocumentFragment $html, string $context): DocumentFragment
	{
		if (is_string($html)) {
			return $this->parseFragment($html, $context);
		}

		$fragment = $this->document->createDocumentFragment();
		foreach ($html->childNodes as $child) {
			$fragment->append($this->document->importNode($child, true));
		}

		return $fragment;
	}

	private function insertFragmentForElement(\Dom\Element $placeholder, DocumentFragment $fragment): void
	{
		$parent = $placeholder->parentNode;
		if (null === $parent) {
			throw new RuntimeException('HTML fragment placeholder has no parent.');
		}

		$parent->insertBefore($fragment, $placeholder);
		$parent->removeChild($placeholder);
	}

	private function parseFragment(string $html, string $context = 'body'): DocumentFragment
	{
		$context = 'head' === strtolower($context) ? 'head' : 'body';
		$fragmentDocument = HTMLDocument::createFromString(
			'<!DOCTYPE html><html><head>'.('head' === $context ? $html : '').'</head><body>'.('body' === $context ? $html : '').'</body></html>',
			LIBXML_COMPACT | LIBXML_NOERROR,
		);
		$container = $fragmentDocument->querySelector($context);
		$fragment = $this->document->createDocumentFragment();
		if (null === $container) {
			return $fragment;
		}

		foreach ($container->childNodes as $child) {
			$fragment->append($this->document->importNode($child, true));
		}

		return $fragment;
	}
}
