<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

use Dom\Document;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSOpaqueRule;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderMode;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderer;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRenderOptions;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSRule;
use Jimbo2150\PhpCssTypedOm\CSSOM\CSSStyleSheet;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class InlineCssFilter extends AbstractFilter
{
	public function __construct(private readonly InlineAssetMinificationOptionsResolver $options)
	{
	}

	public function filter(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		if (!$event->isMainRequest()
			|| !self::isHtmlResponseType($request, $event->getResponse())
			|| !$this->options->resolveForRequest($request)->cssMinify) {
			return;
		}

		$document = self::getHtmlDocument($event);
		$changed = false;
		foreach ($document->querySelectorAll('style') as $style) {
			$source = $style->textContent;
			if ('' === $source || InlineAssetMinificationHeuristic::containsPreservedCssComment($source)) {
				continue;
			}

			try {
				$stylesheet = CSSStyleSheet::parse($source);
				if ([] !== $stylesheet->getParseErrors()) {
					continue;
				}
				$minified = CSSRenderer::render($stylesheet, new CSSRenderOptions(
					mode: CSSRenderMode::Minified,
					keepComments: false,
					ruleCallback: static function (CSSRule $rule, string $text): string {
						if ($rule instanceof CSSOpaqueRule) {
							return str_replace(["\r\n", "\r", "\n"], '', $text);
						}

						return $text;
					},
				));
			} catch (\Throwable) {
				continue;
			}
			if (strlen($minified) < strlen($source)) {
				$style->textContent = $minified;
				$changed = true;
			}
		}

		if ($changed) {
			self::saveHtmlDocument($event, $document);
		}
	}
}
