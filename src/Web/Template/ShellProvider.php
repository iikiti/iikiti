<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Template;

use iikiti\CMS\Entity\Object\Shell;
use iikiti\CMS\Repository\Object\ShellRepository;

/**
 * Loads enabled global shells and groups their stored data by role. Shell
 * selection per request is left to {@see ShellResolver}.
 */
final class ShellProvider
{
	public function __construct(
		private readonly ShellRepository $shellRepository,
	) {
	}

	/**
	 * @return array<string,list<array<string,mixed>>> shell records keyed by role
	 */
	public function byRole(): array
	{
		$grouped = [];
		/** @var list<Shell> $shells */
		$shells = $this->shellRepository->findAll();
		foreach ($shells as $shell) {
			$grouped[$shell->getRole()][] = [
				'id' => $shell->getId(),
				'role' => $shell->getRole(),
				'name' => $shell->getName(),
				'priority' => $shell->getPriority(),
				'enabled' => $shell->isEnabled(),
				'display_rules' => $shell->getDisplayRules(),
				'blocks' => $shell->getBlocks(),
			];
		}

		return $grouped;
	}
}
