<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Twig;

use iikiti\CMS\Service\LocaleFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Twig filters for request-locale date, time, and numeric formatting. */
final class LocaleFormatExtension extends AbstractExtension
{
	public function __construct(
		private readonly LocaleFormatter $formatter,
	) {
	}

	public function getFilters(): array
	{
		return [
			new TwigFilter('iikiti_format_date', $this->formatter->formatDate(...)),
			new TwigFilter('iikiti_format_time', $this->formatter->formatTime(...)),
			new TwigFilter('iikiti_format_datetime', $this->formatter->formatDateTime(...)),
			new TwigFilter('iikiti_format_number', $this->formatter->formatNumber(...)),
		];
	}
}
