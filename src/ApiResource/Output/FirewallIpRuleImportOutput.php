<?php

declare(strict_types=1);

namespace iikiti\CMS\ApiResource\Output;

use iikiti\CMS\ApiResource\FirewallIpRuleResource;

final readonly class FirewallIpRuleImportOutput
{
	/** @param list<FirewallIpRuleResource> $rules */
	public function __construct(
		public int $importedCount,
		public array $rules,
	) {
	}
}
