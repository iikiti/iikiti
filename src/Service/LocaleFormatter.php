<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Event\Subscriber\RequestLocaleSubscriber;
use iikiti\CMS\Value\LocaleTag;
use Symfony\Component\HttpFoundation\RequestStack;

/** Formats server-rendered dates, times, and numbers for the active request. */
final readonly class LocaleFormatter
{
	public function __construct(
		private RequestStack $requestStack,
	) {
	}

	public function formatDate(\DateTimeInterface|string $value, ?string $contentLocale = null): string
	{
		return $this->formatDateValue($value, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, $contentLocale);
	}

	public function formatTime(\DateTimeInterface|string $value, ?string $contentLocale = null): string
	{
		return $this->formatDateValue($value, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, $contentLocale);
	}

	public function formatDateTime(\DateTimeInterface|string $value, ?string $contentLocale = null): string
	{
		return $this->formatDateValue($value, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, $contentLocale);
	}

	public function formatNumber(int|float $value, ?string $contentLocale = null): string
	{
		$formatter = new \NumberFormatter($this->locale($contentLocale), \NumberFormatter::DECIMAL);
		$formatted = $formatter->format($value);

		return false === $formatted ? (string) $value : $formatted;
	}

	private function formatDateValue(\DateTimeInterface|string $value, int $dateType, int $timeType, ?string $contentLocale): string
	{
		try {
			$date = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value);
		} catch (\Exception) {
			return '';
		}

		$formatter = new \IntlDateFormatter(
			$this->locale($contentLocale),
			$dateType,
			$timeType,
			$this->timeZone(),
		);
		$formatted = $formatter->format($date->getTimestamp());

		return false === $formatted ? '' : $formatted;
	}

	private function locale(?string $contentLocale): string
	{
		$request = $this->requestStack->getCurrentRequest();
		$context = $request?->attributes->get(RequestLocaleSubscriber::REQUEST_CONTEXT_ATTRIBUTE);
		$locale = is_array($context) ? ($context['userPreferred'] ?? $contentLocale ?? $context['presentation'] ?? null) : $contentLocale;
		if (!is_string($locale) || '' === $locale) {
			$locale = str_replace('_', '-', $request?->getLocale() ?? 'en');
		}

		try {
			return LocaleTag::fromString($locale)->toIcuLocale();
		} catch (\InvalidArgumentException) {
			return 'en';
		}
	}

	private function timeZone(): string
	{
		$request = $this->requestStack->getCurrentRequest();
		$context = $request?->attributes->get(RequestLocaleSubscriber::REQUEST_CONTEXT_ATTRIBUTE);
		$timeZone = is_array($context) ? ($context['timeZone'] ?? null) : null;
		if (!is_string($timeZone) || '' === $timeZone) {
			return date_default_timezone_get();
		}

		try {
			return (new \DateTimeZone($timeZone))->getName();
		} catch (\Exception) {
			return date_default_timezone_get();
		}
	}
}
