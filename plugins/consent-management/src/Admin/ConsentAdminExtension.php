<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Admin;

use iikiti\CMS\Admin\AdminExtensionInterface;
use iikiti\CMS\Admin\AdminExtensionTrait;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminScreen;

final class ConsentAdminExtension implements AdminExtensionInterface
{
	use AdminExtensionTrait;

	public function getMenuItems(): array
	{
		return [
			AdminMenuItem::create(
				'Privacy & Consent',
				'/privacy/consent-log',
				'shield-alert',
				55,
				[
					AdminMenuItem::create('Consent Log', '/privacy/consent-log', null, 0, [], 'ROLE_ADMIN'),
					AdminMenuItem::create('Banner Configuration', '/privacy/banner-config', null, 1, [], 'ROLE_ADMIN'),
				],
				'ROLE_ADMIN'
			),
		];
	}

	public function getAdminScreens(): array
	{
		return [
			new AdminScreen(
				path: '/privacy/consent-log',
				title: 'Consent Log',
				type: 'list',
				apiPath: '/admin/privacy/consent-log',
				permission: 'ROLE_ADMIN',
				icon: 'history',
				config: [
					'columns' => [
						['key' => 'id', 'label' => 'ID'],
						['key' => 'userId', 'label' => 'User'],
						['key' => 'sessionId', 'label' => 'Session'],
						['key' => 'siteId', 'label' => 'Site'],
						['key' => 'categories', 'label' => 'Categories'],
						['key' => 'legalFramework', 'label' => 'Framework'],
						['key' => 'geoCountry', 'label' => 'Country'],
						['key' => 'proofToken', 'label' => 'Proof Token'],
						['key' => 'consentedAt', 'label' => 'Consented At'],
					],
				],
			),
			new AdminScreen(
				path: '/privacy/banner-config',
				title: 'Banner Configuration',
				type: 'form',
				apiPath: '/api/admin/privacy/config',
				permission: 'ROLE_ADMIN',
				icon: 'settings',
				config: [
					'editPath' => '/privacy/banner-config',
					'fields' => [
						['key' => 'version', 'label' => 'Consent version', 'type' => 'number', 'required' => true,
						 'description' => 'Bumping this forces all visitors to re-consent.'],
						['key' => 'default_categories', 'label' => 'Default categories', 'type' => 'json',
						 'description' => 'JSON e.g. {"necessary":true,"analytics":false,"marketing":false}.'],
						['key' => 'framework_override', 'label' => 'Framework override', 'type' => 'text',
						 'description' => 'gdpr, ccpa, lgpd, pipeda or none. Empty = auto-detect from geo.'],
					],
				],
			),
		];
	}

	public function getResources(): array
	{
		return [];
	}
}
