<?php

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\PluginConfigurationOperation;
use iikiti\CMS\Plugin\Exception\PluginException;
use iikiti\CMS\Plugin\Exception\PluginNotFoundException;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Service\Configuration;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<PluginConfigurationOperation, PluginConfigurationOperation> */
#[AutoconfigureTag('api_platform.state_processor')]
readonly class PluginConfigurationProcessor implements ProcessorInterface
{
	public function __construct(
		private PluginManager $pluginManager,
		private EntityManagerInterface $entityManager,
	) {
	}

	#[\Override]
	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
	{
		try {
			if ('admin_plugin_migrate_configuration' === $operation->getName()) {
				$this->migrate($data);
			} else {
				$this->configure($data);
			}
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

	private function configure(PluginConfigurationOperation $operation): void
	{
		if ('' === trim($operation->slug)) {
			throw new PluginException('A plugin slug is required.');
		}
		if (!isset($this->pluginManager->getInstalled()[$operation->slug])) {
			throw new PluginNotFoundException(sprintf('Plugin "%s" is not installed.', $operation->slug));
		}

		$hasChanges = [] !== $operation->set || [] !== $operation->unset;
		if ($hasChanges && null === $operation->siteId) {
			throw new PluginException('A site id is required when setting plugin configuration.');
		}
		if (!$hasChanges && null === $operation->siteId) {
			$operation->configuration = [];
			$operation->success = true;
			$operation->message = 'Plugin configuration loaded.';

			return;
		}

		foreach ($this->pluginManager->getSites() as $site) {
			if ((string) $site->getId() !== $operation->siteId) {
				continue;
			}

			$configuration = $site->getConfiguration();
			$config = (array) $configuration->getPluginConfiguration($operation->slug);
			if (!$hasChanges) {
				$operation->configuration = $config;
				$operation->success = true;
				$operation->message = 'Plugin configuration loaded.';

				return;
			}

			foreach ($operation->set as $pair) {
				if (!str_contains($pair, '=')) {
					throw new PluginException(sprintf('Invalid --set value "%s"; expected key=value.', $pair));
				}
				[$key, $value] = explode('=', $pair, 2);
				$config[$key] = $value;
			}
			foreach ($operation->unset as $key) {
				unset($config[$key]);
			}

			$configuration->setPluginConfiguration($operation->slug, $config);
			$site->setConfiguration($configuration);
			$this->entityManager->flush();

			$operation->configuration = $config;
			$operation->success = true;
			$operation->message = sprintf('Configuration updated for "%s" on site %s.', $operation->slug, $operation->siteId);

			return;
		}

		if ($hasChanges) {
			throw new PluginException(sprintf('Site "%s" was not found.', $operation->siteId));
		}

		$operation->configuration = [];
		$operation->success = true;
		$operation->message = 'Plugin configuration loaded.';
	}

	private function migrate(PluginConfigurationOperation $operation): void
	{
		$migrated = 0;
		foreach ($this->pluginManager->getSites() as $site) {
			$configuration = $site->getConfiguration();
			$json = (array) $configuration->getJson();

			if (!isset($json['extensions']) || !is_array($json['extensions'])) {
				continue;
			}

			$plugins = isset($json['plugins']) && is_array($json['plugins']) ? $json['plugins'] : [];
			$json['plugins'] = array_replace($json['extensions'], $plugins);
			unset($json['extensions']);
			$site->setConfiguration(new Configuration($json));
			++$migrated;
		}

		if ($migrated > 0) {
			$this->entityManager->flush();
		}

		$operation->success = true;
		$operation->message = sprintf('Migrated configuration for %d site(s).', $migrated);
		$operation->result = ['migratedSites' => $migrated];
	}
}
