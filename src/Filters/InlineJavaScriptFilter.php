<?php

declare(strict_types=1);

namespace iikiti\CMS\Filters;

use MatthiasMullie\Minify\JS;
use Peast\Peast;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class InlineJavaScriptFilter extends AbstractFilter
{
	private const SCRIPT_TYPES = [
		'application/ecmascript',
		'application/javascript',
		'text/ecmascript',
		'text/javascript',
	];

	public function __construct(
		private readonly InlineAssetMinificationOptionsResolver $options,
		private readonly TerserUglifier $uglifier,
	) {
	}

	public function filter(ResponseEvent $event): void
	{
		$request = $event->getRequest();
		if (!$event->isMainRequest()
			|| !self::isHtmlResponseType($request, $event->getResponse())) {
			return;
		}
		$options = $this->options->resolveForRequest($request);
		if (!$options->jsMinify) {
			return;
		}

		$document = self::getHtmlDocument($event);
		/** @var list<array{element:\Dom\Element,source:string,minified:string}> $pendingScripts */
		$pendingScripts = [];
		foreach ($document->querySelectorAll('script') as $script) {
			$type = $script->getAttribute('type');
			if ($script->hasAttribute('src') || !self::isJavaScriptType(is_string($type) ? strtolower(trim($type)) : '')) {
				continue;
			}
			$source = $script->textContent;
			if ('' === $source || InlineAssetMinificationHeuristic::isAlreadyMinified($source)) {
				continue;
			}

			try {
				Peast::latest($source, [
					'sourceType' => 'module' === strtolower(trim(is_string($type) ? $type : ''))
						? Peast::SOURCE_TYPE_MODULE
						: Peast::SOURCE_TYPE_SCRIPT,
				])->parse();
				$minified = (new JS($source))->minify();
			} catch (\Throwable) {
				continue;
			}
			if (strlen($minified) < strlen($source)) {
				$pendingScripts[] = ['element' => $script, 'source' => $source, 'minified' => $minified];
			}
		}

		if ($options->jsUglify && [] !== $pendingScripts) {
			try {
				$uglifiedScripts = $this->uglifier->minifyAll(array_column($pendingScripts, 'minified'));
				foreach ($uglifiedScripts as $index => $uglified) {
					if (strlen($uglified) < strlen($pendingScripts[$index]['minified'])) {
						$pendingScripts[$index]['minified'] = $uglified;
					}
				}
			} catch (\Throwable) {
				// The safe whitespace/comment minification remains usable without Node/Terser.
			}
		}

		$changed = false;
		foreach ($pendingScripts as $pendingScript) {
			if (strlen($pendingScript['minified']) >= strlen($pendingScript['source'])) {
				continue;
			}
			$pendingScript['element']->textContent = $pendingScript['minified'];
			$changed = true;
		}

		if ($changed) {
			self::saveHtmlDocument($event, $document);
		}
	}

	private static function isJavaScriptType(string $type): bool
	{
		return '' === $type || 'module' === $type || in_array($type, self::SCRIPT_TYPES, true);
	}
}
