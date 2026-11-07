<?php

declare(strict_types=1);

namespace iikiti\CMS\Response\Composition;

use Dom\HTMLDocument;
use iikiti\CMS\Async\Promise;

/** Opt-in response plan returned by ordinary Symfony controller actions. */
final readonly class ControllerResponsePlan
{
	/**
	 * @param array<array-key,Promise> $operations
	 * @param array<string,string> $headers
	 */
	private function __construct(
		public ResponseFragmentFormat $format,
		public array $operations,
		public int $status,
		public array $headers,
		public string|HTMLDocument|null $documentHtml = null,
		public ?string $contentType = null,
		public float $timeoutSeconds = 5.0,
	) {
	}

	/** @param array<array-key,Promise> $operations
	 *  @param array<string,string> $headers
	 */
	public static function html(
		string|HTMLDocument $documentSkeleton,
		array $operations,
		int $status = 200,
		array $headers = [],
		float $timeoutSeconds = 5.0,
	): self {
		return new self(ResponseFragmentFormat::Html, $operations, $status, $headers, $documentSkeleton, null, $timeoutSeconds);
	}

	/** @param array<array-key,Promise> $operations
	 *  @param array<string,string> $headers
	 */
	public static function jsonObject(
		array $operations,
		int $status = 200,
		array $headers = [],
		float $timeoutSeconds = 5.0,
	): self {
		return new self(ResponseFragmentFormat::JsonObject, $operations, $status, $headers, null, null, $timeoutSeconds);
	}

	/** @param array<array-key,Promise> $operations
	 *  @param array<string,string> $headers
	 */
	public static function jsonArray(
		array $operations,
		int $status = 200,
		array $headers = [],
		float $timeoutSeconds = 5.0,
	): self {
		return new self(ResponseFragmentFormat::JsonArray, $operations, $status, $headers, null, null, $timeoutSeconds);
	}

	/** @param array<array-key,Promise> $operations
	 *  @param array<string,string> $headers
	 */
	public static function text(
		array $operations,
		string $contentType = 'text/plain; charset=UTF-8',
		int $status = 200,
		array $headers = [],
		float $timeoutSeconds = 5.0,
	): self {
		return new self(ResponseFragmentFormat::Text, $operations, $status, $headers, null, $contentType, $timeoutSeconds);
	}
}
