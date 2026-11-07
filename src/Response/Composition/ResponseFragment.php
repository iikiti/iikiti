<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

use Dom\DocumentFragment;

final readonly class ResponseFragment
{
	private function __construct(
		public ResponseFragmentFormat $format,
		public mixed $value,
		public int $order,
		public ?string $target = null,
	) {
	}

	public static function html(string $target, string|DocumentFragment $html, int $order = 0): self
	{
		if ('' === trim($target)) {
			throw new \InvalidArgumentException('An HTML fragment target selector is required.');
		}

		return new self(ResponseFragmentFormat::Html, $html, $order, $target);
	}

	/** @param array<array-key,mixed> $values */
	public static function jsonObject(array $values, int $order = 0): self
	{
		return new self(ResponseFragmentFormat::JsonObject, $values, $order);
	}

	/** @param array<mixed> $values */
	public static function jsonArray(array $values, int $order = 0): self
	{
		if (!array_is_list($values)) {
			throw new \InvalidArgumentException('A JSON array fragment must be a list.');
		}

		return new self(ResponseFragmentFormat::JsonArray, $values, $order);
	}

	public static function text(string $text, int $order = 0): self
	{
		return new self(ResponseFragmentFormat::Text, $text, $order);
	}
}
