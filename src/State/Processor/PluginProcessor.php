<?php

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use iikiti\CMS\ApiResource\PluginOperation;
use iikiti\CMS\Plugin\Exception\PluginException;
use iikiti\CMS\Plugin\Exception\PluginNotFoundException;
use iikiti\CMS\Plugin\PluginAutoloader;
use iikiti\CMS\Plugin\PluginDownloader;
use iikiti\CMS\Plugin\PluginLoader;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginValidator;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Handles plugin management operations issued through the admin API.
 *
 * @implements ProcessorInterface<PluginOperation, PluginOperation>
 */
#[AutoconfigureTag('api_platform.state_processor')]
readonly class PluginProcessor implements ProcessorInterface
{
	public function __construct(
		private PluginManager $pluginManager,
		private PluginDownloader $pluginDownloader,
		private PluginValidator $validator,
		private PluginLoader $loader,
	) {
	}

	#[\Override]
	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
	{
		try {
			match ($operation->getName()) {
				'admin_plugin_install' => $this->install($data),
				'admin_plugin_update' => $this->update($data),
				'admin_plugin_remove' => $this->remove($data),
				'admin_plugin_enable' => $this->enable($data),
				'admin_plugin_disable' => $this->disable($data),
				'admin_plugin_verify' => $this->verify($data),
				default => throw new PluginException(sprintf('Unknown plugin operation "%s".', (string) $operation->getName())),
			};
		} catch (PluginException $exception) {
			$data->success = false;
			$data->message = $exception->getMessage();

			if ($exception instanceof PluginNotFoundException) {
				throw new NotFoundHttpException($exception->getMessage(), $exception);
			}

			throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
		}

		return $data;
	}

	private function install(PluginOperation $operation): void
	{
		$version = $operation->version;
		if (null === $version) {
			$update = $this->pluginDownloader->checkForUpdate($operation->slug, '0.0.0', $operation->storeUrl);
			$version = isset($update['version']) ? (string) $update['version'] : null;
		}
		if (null === $version) {
			throw new PluginException(sprintf('Could not resolve a version for "%s".', $operation->slug));
		}

		$package = $this->pluginDownloader->download($operation->slug, $version, $operation->storeUrl);
		$manifest = $this->pluginManager->installPackage($package);

		$this->activateScope($operation);

		$operation->success = true;
		$operation->message = sprintf('Installed %s %s.', $manifest->name, $manifest->version);
		$operation->result = ['slug' => $manifest->slug, 'version' => $manifest->version];
	}

	private function update(PluginOperation $operation): void
	{
		$this->requireInstalled($operation->slug);
		$current = $this->pluginManager->getInstalled()[$operation->slug]['manifest']->version;

		$version = $operation->version;
		if (null === $version) {
			$update = $this->pluginDownloader->checkForUpdate($operation->slug, $current, $operation->storeUrl);
			if (null === $update || !isset($update['version'])) {
				$operation->success = true;
				$operation->message = sprintf('Plugin "%s" is up to date.', $operation->slug);

				return;
			}
			$version = (string) $update['version'];
		}

		if ($version === $current) {
			$operation->success = true;
			$operation->message = sprintf('Plugin "%s" is already at version %s.', $operation->slug, $current);

			return;
		}

		if ($operation->dryRun) {
			$operation->success = true;
			$operation->message = sprintf('Update available: %s -> %s.', $current, $version);
			$operation->result = ['version' => $version];

			return;
		}

		$package = $this->pluginDownloader->download($operation->slug, $version, $operation->storeUrl);
		$manifest = $this->pluginManager->updatePackage($package);

		$operation->success = true;
		$operation->message = sprintf('Updated to %s.', $manifest->version);
		$operation->result = ['slug' => $manifest->slug, 'version' => $manifest->version];
	}

	private function remove(PluginOperation $operation): void
	{
		$this->requireInstalled($operation->slug);
		$this->pluginManager->remove($operation->slug);

		$operation->success = true;
		$operation->message = sprintf('Removed plugin "%s".', $operation->slug);
	}

	private function enable(PluginOperation $operation): void
	{
		$this->requireInstalled($operation->slug);
		$count = $this->activateScope($operation);

		$operation->success = true;
		$operation->message = sprintf('Enabled plugin "%s" for %d site(s).', $operation->slug, $count);
	}

	private function disable(PluginOperation $operation): void
	{
		$this->requireInstalled($operation->slug);
		$count = $this->deactivateScope($operation);

		$operation->success = true;
		$operation->message = sprintf('Disabled plugin "%s" for %d site(s).', $operation->slug, $count);
	}

	private function verify(PluginOperation $operation): void
	{
		$this->requireInstalled($operation->slug);

		$operation->success = true;
		$operation->message = 'Plugin verified.';
		$operation->result = $this->verifyChecks($operation->slug);
	}

	/**
	 * @return int the number of sites actually activated
	 */
	private function activateScope(PluginOperation $operation): int
	{
		if ([] === $operation->sites) {
			return $this->pluginManager->activateForAllSites($operation->slug);
		}

		return $this->pluginManager->activateForSites($operation->slug, array_map('strval', $operation->sites));
	}

	/**
	 * @return int the number of sites actually deactivated
	 */
	private function deactivateScope(PluginOperation $operation): int
	{
		if ([] === $operation->sites) {
			return $this->pluginManager->disableForAllSites($operation->slug);
		}

		return $this->pluginManager->disableForSites($operation->slug, array_map('strval', $operation->sites));
	}

	/**
	 * @return array<string,bool>
	 */
	private function verifyChecks(string $slug): array
	{
		$plugin = $this->pluginManager->getInstalled()[$slug];
		$manifest = $plugin['manifest'];

		$this->validator->validateManifest($manifest, $plugin['installInfo']?->source->isTrusted() ?? false);
		PluginAutoloader::registerPlugin($manifest, $plugin['path']);
		$this->validator->validateBundleClass($manifest, $plugin['path']);

		$linkPath = $this->loader->getActivePath().'/'.$slug;
		$active = is_link($linkPath);
		if ($active) {
			$this->validator->resolveSymlinkTarget($linkPath, $this->loader->getInstalledPath());
		}

		return ['manifest' => true, 'bundle' => true, 'active' => $active];
	}

	private function requireInstalled(string $slug): void
	{
		if (!isset($this->pluginManager->getInstalled()[$slug])) {
			throw new PluginNotFoundException(sprintf('Plugin "%s" is not installed.', $slug));
		}
	}
}
