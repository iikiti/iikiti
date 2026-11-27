<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

use Dom\DocumentFragment;
use Dom\HTMLDocument;
use Jimbo2150\CssIndexedDom\IndexedHtmlDocument;
use RuntimeException;

/** Inserts trusted rendered HTML fragments at explicit placeholders. */
final class HtmlFragmentComposer
{
	/** @var list<array{selector:string,html:string|DocumentFragment,order:int,sequence:int}> */
	private array $fragments = [];
	private int $sequence = 0;
	private readonly IndexedHtmlDocument $indexedDocument;

	public function __construct(string|HTMLDocument|IndexedHtmlDocument $documentHtml)
	{
		if ($documentHtml instanceof IndexedHtmlDocument) {
			$this->indexedDocument = $documentHtml;
		} elseif ($documentHtml instanceof HTMLDocument) {
			$this->indexedDocument = IndexedHtmlDocument::fromDocument($documentHtml, lazyIndex: true);
		} else {
			$this->indexedDocument = IndexedHtmlDocument::createFromString($documentHtml, LIBXML_COMPACT | LIBXML_NOERROR, lazyIndex: true);
		}
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
		$placeholder = $this->indexedDocument->querySelector($selector);
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
		return null !== $this->indexedDocument->querySelector($selector);
	}

	/** @param array<string,string> $embeddedHtml async embed token => HTML */
	public function replaceDeferredEmbeds(array $embeddedHtml): void
	{
		$this->flush();
		$placeholders = $this->indexedDocument->querySelectorAll('template[data-iikiti-async-embed]');
		foreach ($placeholders as $placeholder) {
			$token = $placeholder->getAttribute('data-iikiti-async-embed');
			$this->replaceNodeForElement($placeholder, $embeddedHtml[$token] ?? '');
		}
	}

	public function document(): HTMLDocument
	{
		$this->flush();

		return $this->indexedDocument->document();
	}

	public function indexedDocument(): IndexedHtmlDocument
	{
		$this->flush();

		return $this->indexedDocument;
	}

	public function render(): string
	{
		$this->flush();

		return $this->indexedDocument->saveHtml();
	}

	private function flush(): void
	{
		$grouped = [];
		foreach ($this->fragments as $fragment) {
			$grouped[$fragment['selector']][] = $fragment;
		}
		$this->fragments = [];

		$document = $this->indexedDocument->document();
		foreach ($grouped as $selector => $fragments) {
			$placeholder = $this->indexedDocument->querySelector($selector);
			$parent = $placeholder?->parentNode;
			if (null === $placeholder || null === $parent) {
				throw new RuntimeException(sprintf('HTML fragment placeholder "%s" was not found.', $selector));
			}

			usort($fragments, static fn (array $left, array $right): int =>
				$left['order'] <=> $right['order'] ?: $left['sequence'] <=> $right['sequence']);
			$combined = $document->createDocumentFragment();
			foreach ($fragments as $fragment) {
				$combined->append($this->fragmentForDocument($fragment['html'], $parent->nodeName));
			}

			$this->indexedDocument->replaceWith($placeholder, $combined);
		}
	}

	private function replaceNode(string $selector, string|DocumentFragment $html): void
	{
		$placeholder = $this->indexedDocument->querySelector($selector);
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

		$document = $this->indexedDocument->document();
		$fragment = $document->createDocumentFragment();
		foreach ($html->childNodes as $child) {
			$fragment->append($document->importNode($child, true));
		}

		return $fragment;
	}

	private function insertFragmentForElement(\Dom\Element $placeholder, DocumentFragment $fragment): void
	{
		if (null === $placeholder->parentNode) {
			throw new RuntimeException('HTML fragment placeholder has no parent.');
		}

		$this->indexedDocument->replaceWith($placeholder, $fragment);
	}

	private function parseFragment(string $html, string $context = 'body'): DocumentFragment
	{
		$document = $this->indexedDocument->document();
		$fragment = $document->createDocumentFragment();
		if ('' === $html) {
			return $fragment;
		}

		$tag = strtolower($context);
		[$prefix, $suffix, $selector] = match ($tag) {
			'head' => ['<!DOCTYPE html><html><head>', '</head><body></body></html>', 'head'],
			'table' => ['<!DOCTYPE html><html><body><table>', '</table></body></html>', 'table'],
			'tbody', 'thead', 'tfoot' => ['<!DOCTYPE html><html><body><table><'.$tag.'>', '</'.$tag.'></table></body></html>', $tag],
			'tr' => ['<!DOCTYPE html><html><body><table><tbody><tr>', '</tr></tbody></table></body></html>', 'tr'],
			'colgroup' => ['<!DOCTYPE html><html><body><table><colgroup>', '</colgroup></table></body></html>', 'colgroup'],
			'select' => ['<!DOCTYPE html><html><body><select>', '</select></body></html>', 'select'],
			'optgroup' => ['<!DOCTYPE html><html><body><select><optgroup>', '</optgroup></select></body></html>', 'optgroup'],
			default => ['<!DOCTYPE html><html><head></head><body>', '</body></html>', 'body'],
		};
		$fragmentDocument = HTMLDocument::createFromString(
			$prefix.$html.$suffix,
			LIBXML_COMPACT | LIBXML_NOERROR,
		);
		$container = $fragmentDocument->querySelector($selector);
		if (null === $container) {
			return $fragment;
		}

		foreach ($container->childNodes as $child) {
			$fragment->append($document->importNode($child, true));
		}

		return $fragment;
	}
}
