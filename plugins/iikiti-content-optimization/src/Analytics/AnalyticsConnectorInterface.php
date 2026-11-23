<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Analytics;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A privacy-aware analytics connector registered by a plugin.
 *
 * Config values are limited to schema-defined scalar settings. The adapter and
 * script URL are code-owned constants and must never be read from stored config.
 */
#[AutoconfigureTag('iikiti.analytics_connector')]
interface AnalyticsConnectorInterface
{
	/** Return the namespaced key in plugin-slug.connector-id form. */
	public function key(): string;

	/** Return the human-readable connector label. */
	public function label(): string;

	/** Return a fixed HTTPS provider dashboard URL, or null when none exists. */
	public function dashboardUrl(): ?string;

	/** Connectors are always gated by the fixed analytics consent category. */
	public function consentCategory(): string;

	/**
	 * Describe accepted stored settings.
	 *
	 * String settings accept maxLength and pattern; enum settings accept an
	 * options map of value => label. Fields are required unless required is false.
	 *
	 * @return array<string, array{type:'string'|'boolean'|'enum',label:non-empty-string,required?:bool,maxLength?:positive-int,pattern?:non-empty-string,options?:array<string,non-empty-string>}>
	 */
	public function settingSchema(): array;

	/**
	 * Validate connector-specific constraints not expressible in the schema.
	 *
	 * @param array<string,scalar> $config
	 * @param bool                 $partial whether required schema fields may be omitted
	 */
	public function validateConfig(array $config, bool $partial = false): void;

	/** Return the code-owned, stable client adapter identifier. */
	public function adapterIdentifier(): string;

	/** Return the code-owned, stable HTTPS script URL. */
	public function scriptUrl(): string;

	/**
	 * Build a descriptor from registry-validated config.
	 *
	 * @param array<string,scalar> $validatedConfig
	 *
	 * @return array{adapter:string,scriptUrl:string,parameters:array<string,scalar>}
	 */
	public function buildClientDescriptor(array $validatedConfig): array;

	/**
	 * Return CSP source expressions; scriptSrc must authorize scriptUrl().
	 * Script rules may be exact paths or slash-terminated path prefixes.
	 *
	 * @return array{scriptSrc:list<string>,connectSrc:list<string>,imgSrc:list<string>}
	 */
	public function cspRequirements(): array;
}
