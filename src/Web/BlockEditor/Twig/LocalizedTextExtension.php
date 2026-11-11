<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Twig;

use iikiti\CMS\Value\LocaleTag;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Renders plain or mixed-language text with HTML-safe language spans. */
final class LocalizedTextExtension extends AbstractExtension
{
	public function getFilters(): array
	{
		return [
			new TwigFilter('iikiti_localized_text', $this->render(...), ['is_safe' => ['html']]),
			new TwigFilter('iikiti_localized_plain_text', $this->renderPlain(...), ['is_safe' => ['html']]),
		];
	}

	public function render(mixed $value): string
	{
		if (is_string($value) || is_int($value) || is_float($value)) {
			return $this->escape((string) $value);
		}
		if (!is_array($value) || !array_is_list($value)) {
			return '';
		}

		$html = '';
		foreach ($value as $segment) {
			if (!is_array($segment) || 2 !== count($segment) || !is_string($segment['locale'] ?? null) || !is_string($segment['text'] ?? null)) {
				return '';
			}
			try {
				$locale = LocaleTag::fromString($segment['locale'])->getTag();
			} catch (\InvalidArgumentException) {
				return '';
			}
			$html .= '<span lang="'.$this->escape($locale).'">'.
				$this->escape($segment['text']).
				'</span>';
		}

		return $html;
	}

	public function renderPlain(mixed $value): string
	{
		if (is_string($value) || is_int($value) || is_float($value)) {
			return $this->escape((string) $value);
		}
		if (!is_array($value) || !array_is_list($value)) {
			return '';
		}

		$text = '';
		foreach ($value as $segment) {
			if (!is_array($segment) || 2 !== count($segment) || !is_string($segment['text'] ?? null) || !is_string($segment['locale'] ?? null)) {
				return '';
			}
			try {
				LocaleTag::fromString($segment['locale']);
			} catch (\InvalidArgumentException) {
				return '';
			}
			$text .= $segment['text'];
		}

		return $this->escape($text);
	}

	private function escape(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
