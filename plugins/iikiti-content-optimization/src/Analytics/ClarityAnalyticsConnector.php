<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Analytics;

final class ClarityAnalyticsConnector implements BuiltInAnalyticsConnectorInterface
{
	public const KEY = 'iikiti-content-optimization.clarity';
	private const SCRIPT_URL_PREFIX = 'https://www.clarity.ms/tag/';

	public function key(): string
	{
		return self::KEY;
	}

	public function label(): string
	{
		return 'Microsoft Clarity';
	}

	public function dashboardUrl(): string
	{
		return 'https://clarity.microsoft.com/';
	}

	public function consentCategory(): string
	{
		return 'analytics';
	}

	public function settingSchema(): array
	{
		return [
			'projectId' => [
				'type' => 'string',
				'label' => 'Project ID',
				'maxLength' => 20,
				'pattern' => '/^[a-z0-9]{6,20}$/D',
			],
		];
	}

	public function validateConfig(array $config, bool $partial = false): void
	{
	}

	public function adapterIdentifier(): string
	{
		return 'clarity';
	}

	public function scriptUrl(): string
	{
		return self::SCRIPT_URL_PREFIX;
	}

	public function buildClientDescriptor(array $validatedConfig): array
	{
		return [
			'adapter' => $this->adapterIdentifier(),
			'scriptUrl' => $this->scriptUrl(),
			'parameters' => $validatedConfig,
		];
	}

	public function cspRequirements(): array
	{
		return [
			'scriptSrc' => [self::SCRIPT_URL_PREFIX, 'https://scripts.clarity.ms/'],
			'connectSrc' => ['https://*.clarity.ms'],
			'imgSrc' => ['https://c.clarity.ms', 'https://c.bing.com'],
		];
	}
}
