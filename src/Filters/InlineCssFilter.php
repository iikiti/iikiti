<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

use Dom\Document;
use MatthiasMullie\Minify\CSS;
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
			if ('' === $source || InlineAssetMinificationHeuristic::isAlreadyMinified($source)) {
				continue;
			}

			try {
				$minified = (new CSS($source))->minify();
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
