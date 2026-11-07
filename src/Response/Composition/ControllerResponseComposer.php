<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

use Dom\DocumentFragment;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Executes controller response operations, then composes typed output fragments. */
final class ControllerResponseComposer
{
	public function __construct(
		private readonly FiberTaskRunner $fiberTaskRunner,
		private readonly ?AsyncResponseDataCollector $profiler = null,
	)
	{
	}

	public function compose(ControllerResponsePlan $plan): Response
	{
		$results = $this->fiberTaskRunner->runPromises($plan->operations, $plan->timeoutSeconds);
		$fragments = [];
		$sequence = 0;
		foreach ($results as $key => $fragment) {
			if (!$fragment instanceof ResponseFragment || $plan->format !== $fragment->format) {
				throw new \UnexpectedValueException(sprintf('Controller task "%s" returned a fragment with the wrong format.', (string) $key));
			}
			$fragments[] = ['fragment' => $fragment, 'sequence' => $sequence++];
		}
		usort($fragments, static fn (array $left, array $right): int =>
			$left['fragment']->order <=> $right['fragment']->order ?: $left['sequence'] <=> $right['sequence']);
		$this->profiler?->recordComposition($plan->format, count($fragments));

		return match ($plan->format) {
			ResponseFragmentFormat::Html => $this->htmlResponse($plan, $fragments),
			ResponseFragmentFormat::JsonObject => $this->jsonObjectResponse($plan, $fragments),
			ResponseFragmentFormat::JsonArray => $this->jsonArrayResponse($plan, $fragments),
			ResponseFragmentFormat::Text => $this->textResponse($plan, $fragments),
		};
	}

	/** @param list<array{fragment:ResponseFragment,sequence:int}> $fragments */
	private function htmlResponse(ControllerResponsePlan $plan, array $fragments): DomHtmlResponse
	{
		if (null === $plan->documentHtml) {
			throw new \LogicException('An HTML response plan needs a document skeleton.');
		}

		$composer = new HtmlFragmentComposer($plan->documentHtml);
		foreach ($fragments as $entry) {
			$fragment = $entry['fragment'];
			if (null === $fragment->target || (!is_string($fragment->value) && !$fragment->value instanceof DocumentFragment)) {
				throw new \UnexpectedValueException('HTML fragments require a target selector and a string or DOM fragment.');
			}
			$composer->insertBefore($fragment->target, $fragment->value, $fragment->order);
		}

		return new DomHtmlResponse($composer->document(), $plan->status, $plan->headers);
	}

	/** @param list<array{fragment:ResponseFragment,sequence:int}> $fragments */
	private function jsonObjectResponse(ControllerResponsePlan $plan, array $fragments): JsonResponse
	{
		$composer = new JsonObjectComposer();
		foreach ($fragments as $entry) {
			if (!is_array($entry['fragment']->value)) {
				throw new \UnexpectedValueException('JSON object fragments must contain keyed arrays.');
			}
			$composer->add($entry['fragment']->value, $entry['fragment']->order);
		}

		return new JsonResponse($composer->compose(), $plan->status, $plan->headers);
	}

	/** @param list<array{fragment:ResponseFragment,sequence:int}> $fragments */
	private function jsonArrayResponse(ControllerResponsePlan $plan, array $fragments): JsonResponse
	{
		$composer = new JsonArrayComposer();
		foreach ($fragments as $entry) {
			if (!is_array($entry['fragment']->value)) {
				throw new \UnexpectedValueException('JSON array fragments must contain list arrays.');
			}
			$composer->add($entry['fragment']->value, $entry['fragment']->order);
		}

		return new JsonResponse($composer->compose(), $plan->status, $plan->headers);
	}

	/** @param list<array{fragment:ResponseFragment,sequence:int}> $fragments */
	private function textResponse(ControllerResponsePlan $plan, array $fragments): Response
	{
		$buffer = fopen('php://temp/maxmemory:2097152', 'w+b');
		if (false === $buffer) {
			throw new RuntimeException('Unable to allocate a text response buffer.');
		}

		try {
			foreach ($fragments as $entry) {
				if (!is_string($entry['fragment']->value)) {
					throw new \UnexpectedValueException('Text fragments must contain strings.');
				}
				$contentLength = strlen($entry['fragment']->value);
				$offset = 0;
				while ($offset < $contentLength) {
					$written = fwrite($buffer, substr($entry['fragment']->value, $offset));
					if (false === $written || 0 === $written) {
						throw new RuntimeException('Unable to write a text response fragment.');
					}
					$offset += $written;
				}
			}
			rewind($buffer);
			$content = stream_get_contents($buffer);
		} finally {
			fclose($buffer);
		}

		$response = new Response(false === $content ? '' : $content, $plan->status, $plan->headers);
		if (null !== $plan->contentType && !$response->headers->has('Content-Type')) {
			$response->headers->set('Content-Type', $plan->contentType);
		}

		return $response;
	}
}
