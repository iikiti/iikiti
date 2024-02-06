<?php

namespace iikiti\CMS\Service;

use iikiti\CMS\Doctrine\Collections\ArrayCollection;
use iikiti\CMS\Service\Configuration as Config;
use Psr\Container\ContainerInterface;

class Configuration implements ContainerInterface
{
	use Config\ExtensionConfigurationTrait;

	protected ArrayCollection $config;

	public function __construct(array $config)
	{
		$this->config = new ArrayCollection($config);
	}

	public function getJson(bool $asObject = false): object|array
	{
		return $asObject ? (object) $this->config->toArray() : $this->config->toArray();
	}

	public function get(string $id): mixed
	{
		return $this->config->get($id);
	}

	public function has(string $id): bool
	{
		return $this->config->containsKey($id);
	}
}
