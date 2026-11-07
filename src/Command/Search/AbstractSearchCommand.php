<?php

namespace iikiti\CMS\Command\Search;

use iikiti\CMS\Api\BusinessApiClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared plumbing for the iikiti:search:* commands.
 */
abstract class AbstractSearchCommand extends Command
{
	public function __construct(
		protected readonly BusinessApiClient $apiClient,
		#[Autowire('%env(string:INTERNAL_API_TOKEN)%')]
		private readonly string $internalApiToken,
	) {
		parent::__construct();
	}

	protected function configureSiteOptions(): void
	{
		$this->
			addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit the operation to one or more site ids.')->
			addOption('all-sites', null, InputOption::VALUE_NONE, 'Apply the operation to every site (default).')->
			addOption('site-host', null, InputOption::VALUE_REQUIRED, 'Use this site host as API context.');
	}

	/**
	 * @return list<int|string>|null null means "all sites"
	 */
	protected function resolveSiteIds(InputInterface $input): ?array
	{
		/** @var list<string> $sites */
		$sites = $input->getOption('site');
		$allSites = (bool) $input->getOption('all-sites');

		if ($allSites || [] === $sites) {
			return null;
		}

		return array_map(static function (string $site): string { return $site; }, $sites);
	}

	/**
	 * @param list<int|string>|null $siteIds
	 */
	/**
	 * @param list<int|string>|null $siteIds
	 * @return array<string,mixed>|null
	 */
	protected function resolveIndex(string $slugOrId, ?array $siteIds, InputInterface $input): ?array
	{
		$indexes = $this->getIndexes($input);

		// Try by id first
		if (ctype_digit($slugOrId)) {
			foreach ($indexes as $index) {
				if ((string) ($index['id'] ?? '') === (string) (int) $slugOrId) {
					return $index;
				}
			}
		}

		// Fall back to slug lookup
		$siteId = null;
		if (null !== $siteIds && 1 === count($siteIds)) {
			$siteId = $siteIds[0];
		}

		$global = null;
		foreach ($indexes as $index) {
			if (($index['slug'] ?? null) !== $slugOrId) {
				continue;
			}

			if (null !== $siteId && (string) ($index['siteId'] ?? '') === (string) $siteId) {
				return $index;
			}

			if (null === ($index['siteId'] ?? null)) {
				$global = $index;
			}
		}

		return $global;
	}

	/** @return list<array<string,mixed>> */
	protected function getIndexes(InputInterface $input): array
	{
		$response = $this->requestApi('GET', '/api/admin/search/indexes', $input);
		$indexes = $response['hydra:member'] ?? $response['member'] ?? $response['items'] ?? $response;
		if (!is_array($indexes) || !array_is_list($indexes)) {
			throw new \UnexpectedValueException('Search index API response must contain a list.');
		}

		return $indexes;
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @return array<string,mixed>
	 */
	protected function requestApi(string $method, string $path, InputInterface $input, ?array $body = null): array
	{
		if ('' === trim($this->internalApiToken)) {
			throw new \LogicException('INTERNAL_API_TOKEN must contain a user-bound API credential to run search commands.');
		}

		$headers = [];
		$siteHost = $input->getOption('site-host');
		if (is_string($siteHost) && '' !== $siteHost) {
			$headers['X-Site-Host'] = $siteHost;
		}

		return $this->apiClient->request($method, $path, $this->internalApiToken, $body, headers: $headers);
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	protected function requestSearchAction(string $path, InputInterface $input, array $body = []): array
	{
		$response = $this->requestApi('POST', $path, $input, $body);
		if ('error' === ($response['status'] ?? null)) {
			throw new \RuntimeException((string) ($response['message'] ?? 'Search API operation failed.'));
		}

		return $response;
	}

	/**
	 * @param list<int|string>|null $siteIds
	 */
	protected function describeScope(?array $siteIds): string
	{
		return null === $siteIds ? 'all sites' : 'sites: '.implode(', ', $siteIds);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	protected function listIndexes(SymfonyStyle $io, InputInterface $input, ?string $type = null, ?string $engine = null): array
	{
		$indexes = $this->getIndexes($input);
		if (null !== $type) {
			$indexes = array_values(array_filter($indexes, static fn (array $index): bool => ($index['type'] ?? null) === $type));
		}
		if (null !== $engine) {
			$indexes = array_values(array_filter($indexes, static fn (array $index): bool => ($index['engine'] ?? null) === $engine));
		}
		usort($indexes, static fn (array $left, array $right): int => strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')));

		if ([] === $indexes) {
			$io->warning('No search index configurations found. Run "iikiti:search:config:init" to create defaults.');

			return [];
		}

		$rows = [];
		foreach ($indexes as $index) {
			$rows[] = [
				$index['id'] ?? '',
				$index['slug'] ?? '',
				$index['name'] ?? '',
				match ($index['type'] ?? 'custom') {
					'frontend' => 'Front-end Search',
					'admin' => 'Administration Search',
					default => 'Custom Index',
				},
				$index['engine'] ?? '',
				($index['isEnabled'] ?? true) ? '<info>Yes</info>' : '<comment>No</comment>',
				($index['systemLocked'] ?? false) ? '<info>Yes</info>' : '<comment>No</comment>',
			];
		}

		$io->table(
			['ID', 'Slug', 'Name', 'Type', 'Engine', 'Enabled', 'System Locked'],
			$rows
		);

		return $indexes;
	}

	protected function formatActionError(string $prefix, \Throwable $exception): string
	{
		$message = $exception->getMessage();

		return str_starts_with($message, $prefix) ? $message : $prefix.$message;
	}
}
