<?php

namespace iikiti\CMS\Service\Configuration;

/**
 * Plugin configuration accessors.
 *
 * Plugins are the primary term. The legacy `extensions` key and the
 * `get*Extension*` method names are retained as aliases so existing stored
 * configuration and callers keep working; new code should use the `plugin`
 * variants.
 */
trait PluginConfigurationTrait
{
	/**
	 * @return array<string,mixed>
	 */
	private function _pluginConfigArray(): array
	{
		$json = $this->getJson();
		$json = is_object($json) ? (array) $json : $json;

		return (array) ($json['plugins'] ?? $json['extensions'] ?? []);
	}

	public function getPluginData(): object
	{
		return (object) $this->_pluginConfigArray();
	}

	/**
	 * @return array<int,string>
	 */
	public function getActivePlugins(): array
	{
		$active = $this->_pluginConfigArray()['active'] ?? [];

		return is_array($active) ? $active : [];
	}

	public function getPluginConfiguration(?string $pluginSlug): object
	{
		$config = $this->_pluginConfigArray()['configuration'] ?? [];
		$config = is_array($config) ? $config : (array) $config;

		if (null !== $pluginSlug && isset($config[$pluginSlug]) && is_array($config[$pluginSlug])) {
			return (object) $config[$pluginSlug];
		}

		return new \stdClass();
	}

	/**
	 * @param array<string,mixed> $values
	 */
	public function setPluginConfiguration(string $pluginSlug, array $values): void
	{
		$data = $this->_pluginConfigArray();
		$configuration = $data['configuration'] ?? [];
		$configuration = is_array($configuration) ? $configuration : (array) $configuration;
		$configuration[$pluginSlug] = $values;
		$data['configuration'] = $configuration;
		$this->set('plugins', $data);
	}

	public function isPluginActive(string $pluginSlug): bool
	{
		return in_array($pluginSlug, $this->getActivePlugins(), true);
	}

	public function enablePlugin(string $pluginSlug): void
	{
		if ($this->isPluginActive($pluginSlug)) {
			return;
		}

		$data = $this->_pluginConfigArray();
		$active = $data['active'] ?? [];
		$active = is_array($active) ? $active : [];
		$active[] = $pluginSlug;
		$data['active'] = array_values(array_unique($active));
		$this->set('plugins', $data);
	}

	public function disablePlugin(string $pluginSlug): void
	{
		$data = $this->_pluginConfigArray();
		$active = $data['active'] ?? [];
		$active = is_array($active) ? $active : [];
		$data['active'] = array_values(array_filter($active, static fn ($slug): bool => $slug !== $pluginSlug));
		$this->set('plugins', $data);
	}

	/**
	 * @deprecated use getPluginData()
	 */
	public function getExtensionData(): object
	{
		return $this->getPluginData();
	}

	/**
	 * @deprecated use getActivePlugins()
	 *
	 * @return array<int,string>
	 */
	public function getActiveExtensions(): array
	{
		return $this->getActivePlugins();
	}

	/**
	 * @deprecated use getPluginConfiguration()
	 */
	public function getExtensionConfiguration(?string $extensionSlug): object
	{
		return $this->getPluginConfiguration($extensionSlug);
	}
}
