<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Analytics;

final class GoogleAnalytics4Connector implements BuiltInAnalyticsConnectorInterface
{
	public const KEY = 'iikiti-content-optimization.ga4';
	private const SCRIPT_URL = 'https://www.googletagmanager.com/gtag/js';

	public function key(): string
	{
		return self::KEY;
	}

	public function label(): string
	{
		return 'Google Analytics 4';
	}

	public function dashboardUrl(): string
	{
		return 'https://analytics.google.com/analytics/web/';
	}

	public function consentCategory(): string
	{
		return 'analytics';
	}

	public function settingSchema(): array
	{
		return [
			'measurementId' => [
				'type' => 'string',
				'label' => 'Measurement ID',
				'maxLength' => 22,
				'pattern' => '/^G-[A-Z0-9]{4,20}$/D',
			],
		];
	}

	public function validateConfig(array $config, bool $partial = false): void
	{
	}

	public function adapterIdentifier(): string
	{
		return 'ga4';
	}

	public function scriptUrl(): string
	{
		return self::SCRIPT_URL;
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
			'scriptSrc' => [self::SCRIPT_URL],
			'connectSrc' => [
				'https://www.google-analytics.com',
				'https://*.google-analytics.com',
				'https://*.analytics.google.com',
			],
			'imgSrc' => ['https://*.google-analytics.com'],
		];
	}
}
