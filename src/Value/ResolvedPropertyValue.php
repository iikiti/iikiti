<?php

declare(strict_types=1);

namespace iikiti\CMS\Value;

/** Selected output for an object property, retaining edit/source metadata. */
final readonly class ResolvedPropertyValue
{
	/** @param string|list<array{text:string,locale:string}>|null $value */
	public function __construct(
		public string|array|null $value,
		public ?LocaleTag $locale,
		public LocalizedValue $localizedValue,
	) {
	}
}
