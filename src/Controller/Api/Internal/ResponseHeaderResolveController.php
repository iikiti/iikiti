<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Internal;

use iikiti\CMS\Service\RemoteResponseHeaderResolver;
use iikiti\CMS\Service\ResponseHeaderResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ResponseHeaderResolveController
{
	/** @param list<string> $consumerHosts */
	public function __construct(
		private ResponseHeaderResolver $resolver,
		#[Autowire('%kernel.secret%')] private string $secret,
		#[Autowire('%iikiti_consumer_hosts%')] private array $consumerHosts,
	) {
	}

	#[Route(RemoteResponseHeaderResolver::PATH, name: 'api_internal_response_headers_resolve', methods: ['POST'])]
	public function __invoke(Request $request): JsonResponse
	{
		try {
			if (strlen($request->getContent()) > 65536) {
				return new JsonResponse(['error' => 'Invalid request'], 400);
			}
			$data = $request->toArray();
			$payload = $data['payload'] ?? null;
			$signature = $data['signature'] ?? null;
			if ('' === trim($this->secret) || !is_array($payload) || !is_string($signature) ||
				!hash_equals(hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $this->secret), $signature) ||
				!is_int($payload['time'] ?? null) || abs(time() - $payload['time']) > 60) {
				return new JsonResponse(['error' => 'Unauthorized'], 401);
			}
			$host = $payload['host'] ?? null;
			$scheme = $payload['scheme'] ?? null;
			$method = $payload['method'] ?? null;
			$path = $payload['path'] ?? null;
			$status = $payload['status'] ?? null;
			$headers = $payload['headers'] ?? null;
			if (!is_string($host) || !preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])$/iD', $host) ||
				!in_array($scheme, ['http', 'https'], true) || !is_string($method) || !preg_match('/^[A-Z]{1,32}$/D', $method) ||
				!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || strlen($path) > 8192 ||
				!is_int($status) || $status < 100 || $status > 599 || !is_array($headers) || count($headers) > 100) {
				return new JsonResponse(['error' => 'Invalid request'], 400);
			}
			$original = Request::create($scheme.'://'.$host.$path, $method);
			foreach ($headers as $name => $value) {
				if (!is_string($name) || !is_string($value) || strlen($name) > 128 || strlen($value) > 4096 ||
					preg_match('/auth|cookie|token|secret|api[-_]?key/i', $name) || preg_match('/[\r\n]/', $value) ||
					!preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name)) {
					return new JsonResponse(['error' => 'Invalid request'], 400);
				}
				$original->headers->set($name, $value);
			}
			$original->headers->set('Host', $host);
			if (!in_array(strtolower($host), array_map('strtolower', $this->consumerHosts), true)) {
				return new JsonResponse(['error' => 'Invalid request'], 400);
			}
			$result = $this->resolver->resolve($original, new Response('', $status), false);
			foreach ($result['rules'] as &$rule) {
				$rule = array_intersect_key($rule, array_flip(['name', 'action', 'value']));
			}
			unset($rule);

			return new JsonResponse($result, 200, ['Cache-Control' => 'no-store']);
		} catch (\JsonException|\Symfony\Component\HttpFoundation\Exception\JsonException $exception) {
			return new JsonResponse(['error' => 'Invalid request'], 400);
		}
	}
}
