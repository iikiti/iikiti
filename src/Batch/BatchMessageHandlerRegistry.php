<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class BatchMessageHandlerRegistry
{
	/** @var array<string,BatchMessageHandlerInterface> */
	private array $handlers = [];

	/** @param iterable<BatchMessageHandlerInterface> $handlers */
	public function __construct(#[AutowireIterator('iikiti.batch.message_handler')] iterable $handlers = [])
	{
		foreach ($handlers as $handler) {
			$type = $handler->getType();
			if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $type) || isset($this->handlers[$type])) {
				throw new \LogicException(sprintf('Invalid or duplicate batch message handler type "%s".', $type));
			}
			$this->handlers[$type] = $handler;
		}
	}

	public function get(string $type): BatchMessageHandlerInterface
	{
		return $this->handlers[$type] ?? throw new \InvalidArgumentException(sprintf('No batch handler is registered for message type "%s".', $type));
	}

	public function has(string $type): bool
	{
		return isset($this->handlers[$type]);
	}

	/** @return list<string> */
	public function getTypes(): array
	{
		return array_keys($this->handlers);
	}
}
