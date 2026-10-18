<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

final readonly class BatchMessage
{
	public string $summary;
	/** @var list<string> */
	public array $dependsOn;

	/**
	 * @param array<string,mixed> $payload
	 * @param list<mixed>         $dependsOn
	 */
	public function __construct(
		public string $key,
		public string $type,
		public array $payload,
		string $summary,
		public string $idempotencyKey,
		array $dependsOn = [],
		public int $version = 1,
	) {
		$summary = preg_replace('/(token|password|secret|credential)\\s*[:=]\\s*\\S+/i', '$1=[redacted]', $summary) ?? 'Batch message';
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $key)) {
			throw new \InvalidArgumentException('Batch message keys must be 1-128 safe characters.');
		}
		if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $type)) {
			throw new \InvalidArgumentException('Batch message types must be lowercase keys.');
		}
		if ('' === trim($summary) || mb_strlen($summary) > 255) {
			throw new \InvalidArgumentException('Batch message summaries must contain 1-255 characters.');
		}
		$this->summary = trim($summary);
		if ('' === trim($idempotencyKey) || mb_strlen($idempotencyKey) > 128) {
			throw new \InvalidArgumentException('Batch message idempotency keys must contain 1-128 characters.');
		}
		if ($version < 1) {
			throw new \InvalidArgumentException('Batch message version must be positive.');
		}
		$validatedDependencies = [];
		foreach ($dependsOn as $dependency) {
			if (!is_string($dependency) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $dependency)) {
				throw new \InvalidArgumentException('Batch message dependencies must be valid item keys.');
			}
			if ($dependency === $key) {
				throw new \InvalidArgumentException('A batch message cannot depend on itself.');
			}
			$validatedDependencies[] = $dependency;
		}
		if (count($validatedDependencies) !== count(array_unique($validatedDependencies))) {
			throw new \InvalidArgumentException('Batch message dependencies must be unique.');
		}
		$this->dependsOn = $validatedDependencies;
		$encoded = json_encode($payload, JSON_THROW_ON_ERROR);
		if (strlen($encoded) > 65536) {
			throw new \InvalidArgumentException('Batch message payloads must not exceed 64 KiB; reference large data externally.');
		}
	}
}
