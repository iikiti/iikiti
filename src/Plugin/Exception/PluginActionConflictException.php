<?php

namespace iikiti\CMS\Plugin\Exception;

final class PluginActionConflictException extends PluginException
{
	/**
	 * @param list<array{site:string,plugin:string,action:string}> $conflicts
	 */
	public function __construct(
		string $message,
		private readonly array $conflicts,
	) {
		$displayConflicts = array_slice($conflicts, 0, 10);
		$details = array_map(
			static fn (array $conflict): string => sprintf('site %s: %s declares %s', $conflict['site'], $conflict['plugin'], $conflict['action']),
			$displayConflicts,
		);
		if (count($conflicts) > count($displayConflicts)) {
			$details[] = sprintf('and %d more conflict(s)', count($conflicts) - count($displayConflicts));
		}
		parent::__construct([] === $details ? $message : $message.' '.implode('; ', $details));
	}

	/** @return list<array{site:string,plugin:string,action:string}> */
	public function getConflicts(): array
	{
		return $this->conflicts;
	}
}
