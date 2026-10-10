<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Icon;

/**
 * Strict allowlist sanitiser for uploaded icon SVG.
 *
 * Policy: an upload is either fully safe and returned as canonical markup, or it is
 * rejected with {@see SvgRejectedException}. Anything outside the allowlist (an
 * unknown element or attribute, a DOCTYPE, a script, an event handler, a style block,
 * foreignObject, an external or javascript: reference) rejects the whole file.
 * Rejecting is deliberately preferred over silently removing parts, so the editor is
 * told the file is unsafe rather than receiving a quietly altered icon.
 *
 * XML is parsed with entity loading and network access disabled, and DOCTYPE
 * declarations are refused before parsing, which blocks XXE and entity expansion.
 */
final class SvgSanitizer
{
	/** Elements an icon may use. Structural and presentational only. */
	private const ALLOWED_ELEMENTS = [
		'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect',
		'defs', 'clipPath', 'mask', 'linearGradient', 'radialGradient', 'stop', 'title', 'desc',
	];

	/** Attributes an icon may carry on any allowed element. */
	private const ALLOWED_ATTRIBUTES = [
		'xmlns', 'viewBox', 'width', 'height', 'preserveAspectRatio',
		'd', 'points', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
		'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap',
		'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity', 'stroke-dasharray',
		'stroke-dashoffset', 'opacity', 'transform', 'id', 'class', 'clip-path', 'mask',
		'clip-rule', 'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform',
		'fx', 'fy', 'role', 'aria-hidden', 'focusable',
	];

	/** Reference attributes; only in-document fragments (`#id`) are permitted. */
	private const REFERENCE_ATTRIBUTES = ['clip-path', 'mask', 'fill', 'stroke'];

	private const MAX_BYTES = 262144;

	/**
	 * @throws SvgRejectedException when the document is not a safe icon
	 */
	public function sanitize(string $svg): string
	{
		if ('' === trim($svg)) {
			throw new SvgRejectedException('SVG is empty.');
		}
		if (strlen($svg) > self::MAX_BYTES) {
			throw new SvgRejectedException('SVG is too large.');
		}
		// A DOCTYPE is the only way to declare entities; refuse it before parsing.
		if (1 === preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
			throw new SvgRejectedException('SVG must not contain a DOCTYPE or entity declaration.');
		}

		$document = new \DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		try {
			// No network, no entity substitution, no external loading.
			$loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if (!$loaded || null === $document->documentElement) {
			throw new SvgRejectedException('SVG is not well-formed XML.');
		}

		$root = $document->documentElement;
		if ('svg' !== $root->localName || 'http://www.w3.org/2000/svg' !== $root->namespaceURI) {
			throw new SvgRejectedException('Root element must be an SVG element.');
		}

		$this->assertTree($root);

		return $document->saveXML($root) ?: throw new SvgRejectedException('SVG could not be serialised.');
	}

	/** Walk the whole tree; any violation throws. */
	private function assertTree(\DOMElement $element): void
	{
		if (!in_array($element->localName, self::ALLOWED_ELEMENTS, true)) {
			throw new SvgRejectedException(sprintf('Element <%s> is not allowed.', $element->localName));
		}
		if ('http://www.w3.org/2000/svg' !== $element->namespaceURI) {
			throw new SvgRejectedException(sprintf('Element <%s> is outside the SVG namespace.', $element->localName));
		}

		foreach ($element->attributes ?? [] as $attribute) {
			$this->assertAttribute($attribute);
		}

		foreach ($element->childNodes as $child) {
			// DOMCdataSection extends DOMText, so this also accepts CDATA. Text is inert.
			if ($child instanceof \DOMText) {
				continue;
			}
			if (!$child instanceof \DOMElement) {
				// Comments and processing instructions are rejected: PIs can carry arbitrary content.
				throw new SvgRejectedException('Comments and processing instructions are not allowed.');
			}
			$this->assertTree($child);
		}
	}

	private function assertAttribute(\DOMAttr $attribute): void
	{
		$name = $attribute->name;

		// Namespace declarations are the only allowed "xmlns*"; the svg xmlns is checked by the root.
		if (str_starts_with($name, 'xmlns')) {
			if ('xmlns' === $name || 'xmlns:xlink' === $name) {
				return;
			}
			throw new SvgRejectedException(sprintf('Namespace declaration "%s" is not allowed.', $name));
		}

		if (str_starts_with(strtolower($name), 'on')) {
			throw new SvgRejectedException(sprintf('Event handler attribute "%s" is not allowed.', $name));
		}

		// `href` / `xlink:href` may only reference the same document: `#id`.
		if ('href' === $attribute->localName || 'xlink:href' === $name) {
			$this->assertFragmentReference($attribute->value, $name);

			return;
		}

		if (!in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
			throw new SvgRejectedException(sprintf('Attribute "%s" is not allowed.', $name));
		}

		if (in_array($name, self::REFERENCE_ATTRIBUTES, true)) {
			$this->assertPaintOrFragment($attribute->value, $name);
		}
	}

	/** href/xlink:href: a same-document fragment only, never a URL or javascript:. */
	private function assertFragmentReference(string $value, string $name): void
	{
		if (1 !== preg_match('/^#[A-Za-z][A-Za-z0-9_.-]*$/', trim($value))) {
			throw new SvgRejectedException(sprintf('Reference "%s" must be a local fragment (#id).', $name));
		}
	}

	/** fill/stroke/clip-path/mask may be a colour, `none`, `currentColor`, or a local url(#id). */
	private function assertPaintOrFragment(string $value, string $name): void
	{
		$trimmed = trim($value);
		if (1 === preg_match('/^(none|currentColor|#[0-9A-Fa-f]{3,8}|[a-z]+)$/', $trimmed)) {
			return;
		}
		if (1 === preg_match('/^url\(#[A-Za-z][A-Za-z0-9_.-]*\)$/', $trimmed)) {
			return;
		}
		throw new SvgRejectedException(sprintf('Value of "%s" is not allowed.', $name));
	}
}
