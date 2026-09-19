<?php

namespace iikiti\CMS\Admin;

use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminApiResource;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by plugins to contribute menu items and API resources to the
 * admin UI.
 *
 * Services implementing this interface are automatically tagged with
 * `iikiti.admin.extension` via {@see AutoconfigureTag}, which means any
 * plugin bundle that auto-registers its services will have its admin
 * extension collected by {@see AdminMenuRegistry}.
 *
 * Example plugin implementation:
 *
 * ```php
 * #[AsService]
 * class ProductsAdminExtension implements AdminExtensionInterface
 * {
 *     public function getMenuItems(): array
 *     {
 *         return [
 *             AdminMenuItem::create('Products', '/products', 'package', 100, [
 *                 AdminMenuItem::create('All Products', '/products'),
 *                 AdminMenuItem::create('Categories', '/products/categories'),
 *             ]),
 *         ];
 *     }
 *
 *     public function getResources(): array
 *     {
 *         return [
 *             new AdminApiResource('Product', 'api_products_get_collection', 'Products'),
 *         ];
 *     }
 * }
 * ```
 */
#[AutoconfigureTag('iikiti.admin.extension')]
interface AdminExtensionInterface
{
	/**
	 * @return list<AdminMenuItem>
	 */
	public function getMenuItems(): array;

	/**
	 * @return list<AdminApiResource>
	 */
	public function getResources(): array;
}
