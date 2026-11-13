<?php

declare(strict_types=1);

namespace iikiti\CMS\Api;

final class BusinessApiException extends \RuntimeException
{
	/** @var array<string,string> */
	public readonly array $responseHeaders;

	/** @param array<string,list<string>> $responseHeaders */
	public function __construct(public readonly int $statusCode, array $responseHeaders = [])
	{
		$this->responseHeaders = self::safeResponseHeaders($responseHeaders);
		parent::__construct(sprintf('Business API returned HTTP %d.', $statusCode));
	}

	/**
	 * @param array<string,list<string>> $headers
	 *
	 * @return array<string,string>
	 */
	private static function safeResponseHeaders(array $headers): array
	{
		$safe = [];
		foreach ($headers as $name => $values) {
			$name = strtolower($name);
			if (!in_array($name, ['allow', 'retry-after'], true)) {
				continue;
			}
			$value = implode(', ', array_filter($values, 'is_string'));
			if ('' === $value || strlen($value) > 256 || 1 === preg_match('/[\r\n]/', $value)) {
				continue;
			}
			$safe[$name] = $value;
		}

		return $safe;
	}
}
