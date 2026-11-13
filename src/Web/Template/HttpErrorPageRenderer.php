<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Renders public HTML errors without depending on page data or composition. */
final readonly class HttpErrorPageRenderer
{
	public function __construct(private Environment $twig)
	{
	}

	/** @param array<string,string> $headers */
	public function render(int $statusCode, array $headers = []): Response
	{
		if ($statusCode < 400 || $statusCode > 599) {
			$statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
		}

		$template = match ($statusCode) {
			Response::HTTP_NOT_FOUND => 'bundles/TwigBundle/Exception/error404.html.twig',
			Response::HTTP_INTERNAL_SERVER_ERROR => 'bundles/TwigBundle/Exception/error500.html.twig',
			default => 'bundles/TwigBundle/Exception/error.html.twig',
		};

		try {
			$content = $this->twig->render($template, ['status_code' => $statusCode]);
		} catch (\Throwable) {
			return self::fallback($statusCode, $headers);
		}

		$response = new Response($content, $statusCode, [
			'Cache-Control' => 'no-store',
			'Content-Type' => 'text/html; charset=UTF-8',
		]);
		self::applySafeHeaders($response, $headers);

		return $response;
	}

	/** @param array<string,string> $headers */
	public static function fallback(int $statusCode, array $headers = []): Response
	{
		if ($statusCode < 400 || $statusCode > 599) {
			$statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
		}

		$content = sprintf(
			'<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Request could not be completed</title><main><h1>Request could not be completed</h1><p>Error %d</p><a href="/">Go to the home page</a></main></html>',
			$statusCode,
		);

		$response = new Response($content, $statusCode, [
			'Cache-Control' => 'no-store',
			'Content-Type' => 'text/html; charset=UTF-8',
		]);
		self::applySafeHeaders($response, $headers);

		return $response;
	}

	/** @param array<string,string> $headers */
	private static function applySafeHeaders(Response $response, array $headers): void
	{
		foreach ($headers as $name => $value) {
			$name = strtolower($name);
			if (!in_array($name, ['allow', 'retry-after'], true) || '' === $value || strlen($value) > 256 || 1 === preg_match('/[\r\n]/', $value)) {
				continue;
			}
			$response->headers->set($name, $value);
		}
	}
}
