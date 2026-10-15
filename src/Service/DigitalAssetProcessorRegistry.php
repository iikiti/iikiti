<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class DigitalAssetProcessorRegistry
{
	/** @var array<string,DigitalAssetProcessorInterface> */
	private array $processors = [];

	/** @param iterable<mixed> $processors */
	public function __construct(#[AutowireIterator('iikiti.digital_asset_processor')] iterable $processors)
	{
		foreach ($processors as $processor) {
			if (!$processor instanceof DigitalAssetProcessorInterface) {
				throw new \InvalidArgumentException('Asset processor services must implement DigitalAssetProcessorInterface.');
			}
			$key = $processor->getKey();
			if (isset($this->processors[$key])) {
				throw new \LogicException(sprintf('Duplicate digital asset processor key "%s".', $key));
			}
			$this->processors[$key] = $processor;
		}
	}

	public function get(string $key): DigitalAssetProcessorInterface
	{
		$processor = $this->processors[$key] ?? null;
		if (!$processor instanceof DigitalAssetProcessorInterface) {
			throw new \InvalidArgumentException(sprintf('Unknown digital asset processor "%s".', $key));
		}

		return $processor;
	}

	public function has(string $key): bool
	{
		return isset($this->processors[$key]);
	}

	/** @return list<string> */
	public function getKeys(): array
	{
		return array_keys($this->processors);
	}
}
