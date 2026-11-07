<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

/**
 * Bounded, immutable request data exposed to firewall conditions.
 */
final readonly class FirewallRequestContext
{
	/**
	 * @param array<string,list<array{name:?string,value:string}>> $values
	 */
	public function __construct(
		private array $values,
		public int|string|null $siteId,
		public string $method,
		public string $path,
		public string $clientIp,
		private int $deadlineNanoseconds = PHP_INT_MAX,
	) {
	}

	public function assertWithinBudget(): void
	{
		if (hrtime(true) > $this->deadlineNanoseconds) {
			throw new FirewallInspectionException('Request exceeded the configured firewall evaluation time limit.');
		}
	}

	/**
	 * @return list<string>
	 */
	public function getValues(string $target, ?string $name = null): array
	{
		$values = [];
		$targets = 'body' === $target ? ['body', 'field', 'upload'] : [$target];
		foreach ($targets as $valueTarget) {
			foreach ($this->values[$valueTarget] ?? [] as $entry) {
				if (null !== $name && 0 !== strcasecmp($entry['name'] ?? '', $name)) {
					continue;
				}
				$values[] = $entry['value'];
			}
		}

		return $values;
	}

	/** @return array{method:string,path:string,siteId:int|string|null,clientIp:string} */
	public function getSafeSummary(): array
	{
		return [
			'method' => $this->method,
			'path' => $this->path,
			'siteId' => $this->siteId,
			'clientIp' => $this->clientIp,
		];
	}
}
