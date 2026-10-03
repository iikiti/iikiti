<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Usage;

/**
 * Computes how many times each registered block type is used and where.
 *
 * Backs the eventual "registered block widgets" admin dashboard. The default
 * implementation scans stored block trees on demand; a persisted index may
 * replace it behind this interface without touching consumers.
 */
interface BlockUsageIndexInterface
{
	/**
	 * @return list<array{
	 *     type: string,
	 *     label: string,
	 *     count: int,
	 *     usedBy: list<array{contextType: string, contextId: int}>
	 * }>
	 */
	public function usage(): array;
}
