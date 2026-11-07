<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

/** Bounded request data and indexes exposed to firewall conditions. */
final class FirewallRequestContext
{
	/** @var array<string,list<string>> */
	private array $valuesByTarget = [];

	/** @var array<string,array<string,list<string>>> */
	private array $valuesByTargetAndName = [];

	/** @var array<string,true> */
	private array $availableIndexKeys = [];

	/** @var array<string,array<string,list<string>>> */
	private array $exactValueIndexes = [];

	/**
	 * @param array<string,list<array{name:?string,value:string}>> $values
	 */
	public function __construct(
		array $values,
		public readonly int|string|null $siteId,
		public readonly string $method,
		public readonly string $path,
		public readonly string $clientIp,
		private readonly int $deadlineNanoseconds = PHP_INT_MAX,
	) {
		foreach ($values as $target => $entries) {
			foreach ($entries as $entry) {
				$this->addValue($target, $entry['name'], $entry['value']);
				if (in_array($target, ['field', 'upload'], true)) {
					$this->addValue('body', $entry['name'], $entry['value']);
				}
			}
		}
		if (!$this->hasValues('method')) {
			$this->addValue('method', null, $this->method);
		}
		if (!$this->hasValues('path')) {
			$this->addValue('path', null, $this->path);
		}
		if (!$this->hasValues('ip')) {
			$this->addValue('ip', null, $this->clientIp);
		}
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
		return null === $name ?
			($this->valuesByTarget[$target] ?? []) :
			($this->valuesByTargetAndName[$target][strtolower($name)] ?? []);
	}

	public function hasValues(string $target, ?string $name = null): bool
	{
		return isset($this->availableIndexKeys[self::indexKey($target, $name)]);
	}

	public function hasIndexKey(string $key): bool
	{
		return isset($this->availableIndexKeys[$key]);
	}

	public function matchesExactValue(string $target, ?string $name, string $needle, bool $caseSensitive): bool
	{
		if (!$this->hasValues($target, $name)) {
			return false;
		}

		$selector = $target."\0".(null === $name ? '*' : strtolower($name))."\0".(int) $caseSensitive;
		if (!isset($this->exactValueIndexes[$selector])) {
			$index = [];
			foreach ($this->getValues($target, $name) as $value) {
				$this->assertWithinBudget();
				if (strlen($value) > 4096) {
					continue;
				}
				$normalized = $caseSensitive ? $value : strtolower($value);
				$index[hash('sha256', $normalized)][] = $normalized;
			}
			$this->exactValueIndexes[$selector] = $index;
		}

		$normalizedNeedle = $caseSensitive ? $needle : strtolower($needle);
		foreach ($this->exactValueIndexes[$selector][hash('sha256', $normalizedNeedle)] ?? [] as $value) {
			if ($value === $normalizedNeedle) {
				return true;
			}
		}

		return false;
	}

	public static function indexKey(string $target, ?string $name = null): string
	{
		return hash('xxh3', $target."\0".(null === $name ? '*' : strtolower($name)));
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

	private function addValue(string $target, ?string $name, string $value): void
	{
		$this->valuesByTarget[$target][] = $value;
		$nameKey = strtolower($name ?? '');
		$this->valuesByTargetAndName[$target][$nameKey][] = $value;
		$this->availableIndexKeys[self::indexKey($target)] = true;
		if (null !== $name) {
			$this->availableIndexKeys[self::indexKey($target, $name)] = true;
		}
	}
}
