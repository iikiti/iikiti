<?php

declare(strict_types=1);

namespace iikiti\CMS\Value;

/** A canonical BCP 47 locale identifier. */
final readonly class LocaleTag implements \Stringable
{
	private function __construct(
		private string $tag,
	) {
	}

	public static function fromString(string $tag): self
	{
		$subtags = explode('-', trim($tag));
		$length = count($subtags);
		if ('x' === strtolower($subtags[0])) {
			if ($length < 2) {
				throw new \InvalidArgumentException(sprintf('Invalid BCP 47 locale "%s".', $tag));
			}
			foreach (array_slice($subtags, 1) as $subtag) {
				if (!self::isPrivateUseValue($subtag)) {
					throw new \InvalidArgumentException(sprintf('Invalid BCP 47 locale "%s".', $tag));
				}
			}

			return new self('x-'.implode('-', array_map('strtolower', array_slice($subtags, 1))));
		}
		$language = strtolower($subtags[0]);

		if (!self::isLanguage($language)) {
			throw new \InvalidArgumentException(sprintf('Invalid BCP 47 locale "%s".', $tag));
		}

		$canonical = [$language];
		$index = 1;

		if (strlen($language) <= 3) {
			$extlangs = 0;
			while ($index < $length && 3 === strlen($subtags[$index]) && ctype_alpha($subtags[$index]) && $extlangs < 3) {
				$canonical[] = strtolower($subtags[$index++]);
				++$extlangs;
			}
		}

		if ($index < $length && 4 === strlen($subtags[$index]) && ctype_alpha($subtags[$index])) {
			$canonical[] = ucfirst(strtolower($subtags[$index++]));
		}

		if ($index < $length && (2 === strlen($subtags[$index]) && ctype_alpha($subtags[$index]) || 3 === strlen($subtags[$index]) && ctype_digit($subtags[$index]))) {
			$canonical[] = ctype_alpha($subtags[$index]) ? strtoupper($subtags[$index++]) : $subtags[$index++];
		}

		$variants = [];
		while ($index < $length && self::isVariant($subtags[$index])) {
			$variant = strtolower($subtags[$index++]);
			if (isset($variants[$variant])) {
				throw new \InvalidArgumentException(sprintf('Locale "%s" contains a duplicate variant.', $tag));
			}
			$variants[$variant] = true;
			$canonical[] = $variant;
		}

		$extensions = [];
		while ($index < $length && self::isExtensionSingleton($subtags[$index])) {
			$singleton = strtolower($subtags[$index++]);
			if (isset($extensions[$singleton])) {
				throw new \InvalidArgumentException(sprintf('Locale "%s" contains a duplicate extension singleton.', $tag));
			}
			$extensions[$singleton] = true;
			$canonical[] = $singleton;
			$extensionValues = 0;

			while ($index < $length && self::isExtensionValue($subtags[$index])) {
				$canonical[] = strtolower($subtags[$index++]);
				++$extensionValues;
			}

			if (0 === $extensionValues) {
				throw new \InvalidArgumentException(sprintf('Locale "%s" has an extension without a value.', $tag));
			}
		}

		if ($index < $length && 'x' === strtolower($subtags[$index])) {
			++$index;
			if ($index === $length) {
				throw new \InvalidArgumentException(sprintf('Locale "%s" has an empty private-use extension.', $tag));
			}

			$canonical[] = 'x';
			while ($index < $length && self::isPrivateUseValue($subtags[$index])) {
				$canonical[] = strtolower($subtags[$index++]);
			}
		}

		if ($index !== $length) {
			throw new \InvalidArgumentException(sprintf('Invalid BCP 47 locale "%s".', $tag));
		}

		return new self(implode('-', $canonical));
	}

	public static function tryFromString(?string $tag): ?self
	{
		if (null === $tag || '' === trim($tag)) {
			return null;
		}

		return self::fromString($tag);
	}

	public function getTag(): string
	{
		return $this->tag;
	}

	public function getLanguage(): string
	{
		return strtolower(explode('-', $this->tag, 2)[0]);
	}

	public function getScript(): ?string
	{
		foreach (array_slice(explode('-', $this->tag), 1) as $subtag) {
			if (4 === strlen($subtag) && ctype_alpha($subtag)) {
				return ucfirst(strtolower($subtag));
			}
			if (1 === strlen($subtag)) {
				break;
			}
		}

		return null;
	}

	/**
	 * Returns progressively less specific tags for translation lookup. The
	 * original locale remains unchanged for regional presentation formatting.
	 *
	 * @return list<string>
	 */
	public function getLookupChain(): array
	{
		$parts = explode('-', $this->tag);
		$chain = [$this->tag];

		while (count($parts) > 1) {
			array_pop($parts);
			if (1 === strlen((string) end($parts))) {
				array_pop($parts);
			}
			$chain[] = implode('-', $parts);
		}

		return array_values(array_unique($chain));
	}

	/** ICU and Symfony locale IDs use underscores; public/storage tags stay BCP 47. */
	public function toIcuLocale(): string
	{
		if ('und' === $this->tag) {
			return 'und';
		}

		return \Locale::canonicalize(str_replace('-', '_', $this->tag)) ?: 'und';
	}

	public function __toString(): string
	{
		return $this->tag;
	}

	private static function isLanguage(string $subtag): bool
	{
		return (strlen($subtag) >= 2 && strlen($subtag) <= 3 || strlen($subtag) >= 5 && strlen($subtag) <= 8) && ctype_alpha($subtag) ||
			4 === strlen($subtag) && ctype_alpha($subtag);
	}

	private static function isVariant(string $subtag): bool
	{
		return (strlen($subtag) >= 5 && strlen($subtag) <= 8 && ctype_alnum($subtag)) ||
			4 === strlen($subtag) && ctype_digit($subtag[0]) && ctype_alnum($subtag);
	}

	private static function isExtensionSingleton(string $subtag): bool
	{
		return 1 === strlen($subtag) && ctype_alnum($subtag) && 'x' !== strtolower($subtag);
	}

	private static function isExtensionValue(string $subtag): bool
	{
		return strlen($subtag) >= 2 && strlen($subtag) <= 8 && ctype_alnum($subtag);
	}

	private static function isPrivateUseValue(string $subtag): bool
	{
		return strlen($subtag) >= 1 && strlen($subtag) <= 8 && ctype_alnum($subtag);
	}
}
