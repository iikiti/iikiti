<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Query;

/**
 * Executes a {@see QueryDefinition} against a configured data source.
 *
 * Plugins register additional sources by implementing {@see QuerySourceInterface}
 * and the `iikiti.cms.query_source` tag; the executor picks the source by the
 * definition's `source` value (core: `objects`).
 *
 * Safety: sources must never accept raw SQL — they translate the structured
 * definition into parameterized ORM/SQL via whitelisted expressions only.
 */
interface QuerySourceInterface
{
	/**
	 * The source id this implementation handles (e.g. `objects`).
	 */
	public function getName(): string;

	/**
	 * Execute the definition scoped to the given site context.
	 *
	 * @return list<mixed> query-result rows (source-dependent shape)
	 */
	public function execute(QueryDefinition $definition, ?int $siteId): array;
}
