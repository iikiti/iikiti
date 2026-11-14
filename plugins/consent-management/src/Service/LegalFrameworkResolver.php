<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Service;

/**
 * Maps an ISO 3166-1 alpha-2 country code to a legal framework identifier.
 *
 * Framework identifiers: `gdpr`, `ccpa`, `lgpd`, `pipeda`, `none`.
 * The mapping can be overridden per site through plugin configuration.
 */
final class LegalFrameworkResolver
{
	/**
	 * Default country -> framework mapping.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_MAPPING = [
		// GDPR (EU/EEA)
		'AT' => 'gdpr', 'BE' => 'gdpr', 'BG' => 'gdpr', 'HR' => 'gdpr', 'CY' => 'gdpr',
		'CZ' => 'gdpr', 'DK' => 'gdpr', 'EE' => 'gdpr', 'FI' => 'gdpr', 'FR' => 'gdpr',
		'DE' => 'gdpr', 'GR' => 'gdpr', 'HU' => 'gdpr', 'IE' => 'gdpr', 'IT' => 'gdpr',
		'LV' => 'gdpr', 'LT' => 'gdpr', 'LU' => 'gdpr', 'MT' => 'gdpr', 'NL' => 'gdpr',
		'PL' => 'gdpr', 'PT' => 'gdpr', 'RO' => 'gdpr', 'SK' => 'gdpr', 'SI' => 'gdpr',
		'ES' => 'gdpr', 'SE' => 'gdpr', 'IS' => 'gdpr', 'LI' => 'gdpr', 'NO' => 'gdpr',
		// CCPA / CPRA (California)
		'US' => 'ccpa',
		// LGPD (Brazil)
		'BR' => 'lgpd',
		// PIPEDA (Canada)
		'CA' => 'pipeda',
	];

	/**
	 * @param array<string,string> $overrides country code -> framework
	 */
	public function __construct(private array $overrides = [])
	{
	}

	public function resolve(?string $country): string
	{
		if (null === $country || '' === $country) {
			return 'none';
		}

		$code = strtoupper($country);

		if (isset($this->overrides[$code])) {
			return $this->overrides[$code];
		}

		return self::DEFAULT_MAPPING[$code] ?? 'none';
	}
}
