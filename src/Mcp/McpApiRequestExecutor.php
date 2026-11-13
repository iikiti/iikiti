<?php

namespace iikiti\CMS\Mcp;

use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Invokes an exact catalog entry through its existing API route or handler.
 */
final class McpApiRequestExecutor
{
	public function __construct(
		private readonly McpApiEndpointCatalog $catalog,
		private readonly RouterInterface $router,
		private readonly KernelInterface $kernel,
		private readonly RequestStack $requestStack,
		private readonly AuthorizationCheckerInterface $authorizationChecker,
		private readonly LoggerInterface $logger,
		#[Autowire('%mcp_api_max_request_bytes%')]
		private readonly int $maxRequestBytes,
		#[Autowire('%mcp_api_max_response_bytes%')]
		private readonly int $maxResponseBytes,
	) {
	}

	/** @param array<string, mixed> $arguments */
	public function execute(string $apiId, array $arguments): CallToolResult
	{
		$endpoint = $this->catalog->get($apiId);
		if (null === $endpoint) {
			return $this->error('Unknown or unavailable API identifier. Use api_list to discover available APIs.');
		}
		try {
			$encodedArguments = json_encode($arguments, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
		} catch (\JsonException) {
			return $this->error('API arguments must contain valid JSON values.');
		}
		if (strlen($encodedArguments) > $this->maxRequestBytes) {
			return $this->error(sprintf('Arguments exceed the %d-byte MCP API request limit.', $this->maxRequestBytes));
		}

		$validationErrors = (new SchemaValidator())->validateAgainstJsonSchema($arguments, $this->catalog->getInputSchema($endpoint));
		if ([] !== $validationErrors) {
			return $this->error('Arguments do not match the API input schema. Use api_describe to review the accepted fields.', ['errorCount' => count($validationErrors)]);
		}

		if (null !== $endpoint->tool) {
			if (!$this->authorizationChecker->isGranted($endpoint->tool->getRequiredPermission())) {
				return $this->error('You are not authorized to call this API function.');
			}
			try {
				$result = $endpoint->tool->execute($arguments);

				return $this->success(['result' => $result]);
			} catch (\Throwable $exception) {
				$this->logFailure($endpoint, $exception);

				return $this->error('The API function failed. See server logs for the correlation ID.', [
					'correlationId' => $this->correlationId(),
				]);
			}
		}

		try {
			return $this->dispatchRoute($endpoint, $arguments);
		} catch (\Throwable $exception) {
			$this->logFailure($endpoint, $exception);

			return $this->error('The API request failed. See server logs for the correlation ID.', [
				'correlationId' => $this->correlationId(),
			]);
		}
	}

	/** @param array<string, mixed> $arguments */
	private function dispatchRoute(McpApiEndpoint $endpoint, array $arguments): CallToolResult
	{
		$pathParams = $arguments['pathParams'] ?? [];
		$query = $arguments['query'] ?? [];
		$body = $arguments['body'] ?? null;
		$hasBody = array_key_exists('body', $arguments);
		$files = $arguments['files'] ?? [];
		if (!is_array($pathParams) || !is_array($query) || !is_array($files) || ($hasBody && !is_array($body) && !is_scalar($body) && null !== $body)) {
			return $this->error('Path parameters, query, and files must be JSON objects; body must be a valid JSON value.');
		}

		$encodedBody = $hasBody ? json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : '';
		if (strlen($encodedBody) > $this->maxRequestBytes) {
			return $this->error(sprintf('Request body exceeds the %d-byte MCP API request limit.', $this->maxRequestBytes));
		}

		$uri = $this->router->generate($endpoint->routeName, $pathParams, RouterInterface::ABSOLUTE_PATH);
		if ([] !== $query) {
			$uri .= '?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
		}

		$outerRequest = $this->requestStack->getCurrentRequest();
		if (null === $outerRequest) {
			return $this->error('The MCP API call has no authenticated HTTP request context.');
		}

		$server = [
			'HTTP_HOST' => $outerRequest->getHost(),
			'HTTPS' => $outerRequest->isSecure() ? 'on' : 'off',
			'HTTP_ACCEPT' => 'application/ld+json, application/json;q=0.9, text/plain;q=0.8',
		];
		foreach (['X-SITE-HOST', 'X-SITE-SCHEME'] as $header) {
			$value = $outerRequest->headers->get($header);
			if (is_string($value) && '' !== $value) {
				$server['HTTP_'.str_replace('-', '_', $header)] = $value;
			}
		}

		$tempFiles = [];
		try {
			$uploadedFiles = $this->createUploadedFiles($files, $tempFiles);
			if ([] !== $uploadedFiles && !is_array($body) && null !== $body) {
				return $this->error('Multipart file requests require an object body.');
			}
			$subrequest = Request::create($uri, $endpoint->method, [], [], $uploadedFiles, $server, $encodedBody);
			$subrequest->headers->set('Accept', $server['HTTP_ACCEPT']);
			if ([] !== $uploadedFiles) {
				$subrequest->request->add(is_array($body) ? $body : []);
				$subrequest->headers->set('Content-Type', 'multipart/form-data');
			} elseif ('' !== $encodedBody) {
				$subrequest->headers->set('Content-Type', 'application/json');
			}

			$response = $this->kernel->handle($subrequest, HttpKernelInterface::SUB_REQUEST, true);
		} finally {
			foreach ($tempFiles as $tempFile) {
				if (is_file($tempFile)) {
					@unlink($tempFile);
				}
			}
		}
		$content = $response->getContent();
		if (false === $content) {
			$content = '';
		}
		$statusCode = $response->getStatusCode();
		if (500 <= $statusCode) {
			$correlationId = $this->correlationId();
			$this->logger->error('MCP API endpoint returned a server error.', [
				'api_id' => $endpoint->id,
				'correlation_id' => $correlationId,
				'status' => $statusCode,
			]);

			return $this->error('The API endpoint failed. Internal error details were withheld.', ['status' => $statusCode, 'correlationId' => $correlationId]);
		}
		if (in_array($statusCode, [401, 403], true)) {
			return $this->error('The API endpoint denied this operation.', ['status' => $statusCode]);
		}
		if (strlen($content) > $this->maxResponseBytes) {
			return $this->success([
				'status' => $statusCode,
				'truncated' => true,
				'message' => 'The API endpoint completed, but its response exceeded the MCP response limit. Do not retry a write request solely because the response was truncated.',
			]);
		}

		$contentType = $response->headers->get('Content-Type', 'application/octet-stream');
		$isText = str_contains($contentType, 'json') || str_contains($contentType, 'text/') || str_contains($contentType, 'xml');
		$decoded = $isText ? json_decode($content, true) : null;
		$result = [
			'status' => $statusCode,
			'contentType' => $contentType,
			'body' => $isText ?
				(JSON_ERROR_NONE === json_last_error() ? $decoded : $content) :
				base64_encode($content),
			'bodyEncoding' => $isText ? 'text-or-json' : 'base64',
		];

		return $statusCode >= 400 ?
			$this->error('The API endpoint rejected the request.', $result) :
			$this->success($result);
	}

	/**
	 * @param array<string, mixed> $files
	 * @param list<string>         $tempFiles
	 *
	 * @return array<string, UploadedFile>
	 */
	private function createUploadedFiles(array $files, array &$tempFiles): array
	{
		if (10 < count($files)) {
			throw new \InvalidArgumentException('At most 10 files may be included in one API request.');
		}

		$uploadedFiles = [];
		foreach ($files as $fieldName => $file) {
			if (1 !== preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $fieldName) ||
				!is_array($file) ||
				!is_string($file['filename'] ?? null) ||
				!is_string($file['contentBase64'] ?? null)) {
				throw new \InvalidArgumentException('Each uploaded file requires a valid field name, filename, and base64 content.');
			}

			$filename = basename($file['filename']);
			if ('' === $filename || 255 < strlen($filename) || str_contains($filename, "\0")) {
				throw new \InvalidArgumentException('An uploaded file has an invalid filename.');
			}
			$content = base64_decode($file['contentBase64'], true);
			if (false === $content || strlen($content) > $this->maxRequestBytes) {
				throw new \InvalidArgumentException('An uploaded file is invalid or exceeds the MCP API request size limit.');
			}

			$tempFile = tempnam(sys_get_temp_dir(), 'iikiti-mcp-');
			if (false === $tempFile) {
				throw new \RuntimeException('Could not prepare the uploaded API file.');
			}
			$tempFiles[] = $tempFile;
			if (false === file_put_contents($tempFile, $content)) {
				throw new \RuntimeException('Could not prepare the uploaded API file.');
			}
			$mimeType = $file['mimeType'] ?? 'application/octet-stream';
			if (!is_string($mimeType) || 1 !== preg_match('~^[a-zA-Z0-9][a-zA-Z0-9.+-]*/[a-zA-Z0-9][a-zA-Z0-9.+-]*$~', $mimeType)) {
				throw new \InvalidArgumentException('An uploaded file has an invalid MIME type.');
			}
			$uploadedFiles[$fieldName] = new UploadedFile($tempFile, $filename, $mimeType, UPLOAD_ERR_OK, true);
		}

		return $uploadedFiles;
	}

	/** @param array<string, mixed> $data */
	private function success(array $data): CallToolResult
	{
		$json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
		if (strlen($json) > $this->maxResponseBytes) {
			$data = [
				'truncated' => true,
				'message' => 'The API function completed, but its response exceeded the MCP response limit. Do not retry a write request solely because the response was truncated.',
			];
			$json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
		}

		return new CallToolResult(
			[new TextContent($json)],
			structuredContent: $data,
		);
	}

	/** @param array<string, mixed> $details */
	private function error(string $message, array $details = []): CallToolResult
	{
		$data = ['error' => $message] + $details;
		$json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
		if (false === $json || strlen($json) > $this->maxResponseBytes) {
			$json = json_encode(['error' => 'The API request failed with an oversized error response.'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
		}

		return CallToolResult::error([
			new TextContent($json),
		]);
	}

	private function logFailure(McpApiEndpoint $endpoint, \Throwable $exception): void
	{
		$this->logger->error('MCP API invocation failed.', [
			'api_id' => $endpoint->id,
			'correlation_id' => $this->correlationId(),
			'exception' => $exception,
		]);
	}

	private function correlationId(): string
	{
		$requestId = $this->requestStack->getCurrentRequest()?->attributes->get('request_id');

		return is_string($requestId) && '' !== $requestId ? $requestId : bin2hex(random_bytes(8));
	}
}
