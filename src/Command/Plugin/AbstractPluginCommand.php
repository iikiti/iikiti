<?php

namespace iikiti\CMS\Command\Plugin;

use iikiti\CMS\Api\BusinessApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared plumbing for the iikiti:plugin:* commands.
 */
abstract class AbstractPluginCommand extends Command
{
	public function __construct(
		protected readonly BusinessApiClient $api,
	) {
		parent::__construct();
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @param array<string,string|int|float|bool|list<string|int>|null> $query
	 * @return array<mixed>
	 */
	protected function requestApi(string $method, string $path, ?array $body = null, array $query = [], ?string $siteHost = null): array
	{
		$token = getenv('INTERNAL_API_TOKEN');
		if (!is_string($token) || '' === trim($token)) {
			$token = $_SERVER['INTERNAL_API_TOKEN'] ?? $_ENV['INTERNAL_API_TOKEN'] ?? null;
		}
		if (!is_string($token) || '' === trim($token)) {
			throw new \RuntimeException('INTERNAL_API_TOKEN must be set to an administrator user API token before using plugin commands.');
		}

		$headers = null === $siteHost || '' === trim($siteHost) ? [] : ['X-SITE-HOST' => $siteHost];

		return $this->api->request($method, $path, $token, $body, $query, $headers);
	}

	protected function configureSiteOptions(): void
	{
		$this->
			addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit the operation to one or more site ids.')->
			addOption('all-sites', null, InputOption::VALUE_NONE, 'Apply the operation to every site (default).');
		$this->configureSiteHostOption();
	}

	protected function configureSiteHostOption(): void
	{
		$this->addOption('site-host', null, InputOption::VALUE_REQUIRED, 'Use this site host as API context.');
	}

	protected function siteHost(InputInterface $input): ?string
	{
		$siteHost = $input->getOption('site-host');

		return is_string($siteHost) && '' !== trim($siteHost) ? $siteHost : null;
	}

	/**
	 * Resolve the site ids targeted by the current invocation.
	 *
	 * @return list<string>|null null means "all sites"
	 */
	protected function resolveSiteIds(InputInterface $input): ?array
	{
		/** @var list<string> $sites */
		$sites = $input->getOption('site');
		$allSites = (bool) $input->getOption('all-sites');

		if ($allSites || [] === $sites) {
			return null;
		}

		return array_map('strval', $sites);
	}

	/**
	 * @param list<string>|null $siteIds null means "all sites"
	 */
	protected function describeScope(?array $siteIds): string
	{
		return null === $siteIds ? 'all sites' : 'sites: '.implode(', ', $siteIds);
	}

	/**
	 * @param array<mixed> $response
	 * @return list<array<string,mixed>>
	 */
	protected function collectionMembers(array $response): array
	{
		$members = $response['hydra:member'] ?? $response;
		if (!is_array($members)) {
			throw new \UnexpectedValueException('The plugin API returned an invalid collection.');
		}

		return array_values(array_filter($members, 'is_array'));
	}

	/**
	 * @param array<mixed> $response
	 */
	protected function operationMessage(array $response): string
	{
		if (($response['success'] ?? false) !== true) {
			throw new \RuntimeException(is_string($response['message'] ?? null) ? $response['message'] : 'The plugin API operation failed.');
		}

		return is_string($response['message'] ?? null) ? $response['message'] : 'Plugin operation completed.';
	}
}
