<?php

namespace iikiti\CMS\Value;

/**
 * A validated IANA time zone identifier (e.g. "Europe/Paris"), usable for any
 * value that needs a zone: users, sites, stored data, or rendering.
 *
 * The identifier is the stored form: plain text, portable, and safe in JSON
 * columns. Callers that need a zone object get one from
 * {@see toIntl()} when the `intl` extension is loaded, or {@see toDateTimeZone()}
 * otherwise. {@see toZone()} picks whichever is available.
 */
final readonly class TimeZone implements \Stringable
{
	private function __construct(
		private string $id,
	) {
	}

	/**
	 * Builds a zone from an IANA identifier.
	 *
	 * @throws \InvalidArgumentException when the identifier is not a known zone
	 */
	public static function fromString(string $id): self
	{
		if (!in_array($id, \DateTimeZone::listIdentifiers(), true)) {
			throw new \InvalidArgumentException(sprintf('Unknown time zone "%s".', $id));
		}

		return new self($id);
	}

	/**
	 * Returns null for null or empty input instead of throwing, so a cleared
	 * preference maps cleanly to "no zone set".
	 */
	public static function fromNullableString(?string $id): ?self
	{
		return null === $id || '' === $id ? null : self::fromString($id);
	}

	public function getId(): string
	{
		return $this->id;
	}

	/**
	 * The ICU zone object; available only when the `intl` extension is loaded.
	 *
	 * @return \IntlTimeZone|null null when `intl` is not loaded
	 */
	public function toIntl(): ?\IntlTimeZone
	{
		if (!class_exists(\IntlTimeZone::class)) {
			return null;
		}

		return \IntlTimeZone::createTimeZone($this->id);
	}

	/**
	 * The core PHP zone object, always available.
	 */
	public function toDateTimeZone(): \DateTimeZone
	{
		return new \DateTimeZone($this->id);
	}

	/**
	 * Preferred zone object: an ICU {@see \IntlTimeZone} when available, otherwise
	 * a {@see \DateTimeZone}.
	 */
	public function toZone(): \IntlTimeZone|\DateTimeZone
	{
		return $this->toIntl() ?? $this->toDateTimeZone();
	}

	public function __toString(): string
	{
		return $this->id;
	}
}
