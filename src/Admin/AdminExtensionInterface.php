<?php

namespace iikiti\CMS\Admin;

use iikiti\CMS\ApiResource\AdminApiResource;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by plugins to contribute menu items, API resources and admin
 * screens to the admin UI.
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
 *     public function getAdminScreens(): array
 *     {
 *         return [
 *             new AdminScreen(
 *                 path: '/products',
 *                 title: 'Products',
 *                 type: 'list',
 *                 apiPath: '/admin/products',
 *                 config: [
 *                     'columns' => [
 *                         ['key' => 'id', 'label' => 'ID'],
 *                         ['key' => 'name', 'label' => 'Name'],
 *                     ],
 *                 ],
 *             ),
 *             new AdminScreen(
 *                 path: '/products/dashboard',
 *                 title: 'Product Dashboard',
 *                 type: 'custom',
 *                 bundle: '/admin-plugins/acme-products/dist/admin.js',
 *                 component: 'ProductDashboard',
 *             ),
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
	 * API resources contributed to the admin UI.
	 *
	 * @return list<AdminApiResource>
	 *
	 * @deprecated Use {@see getAdminScreens()} instead — screen descriptors
	 *             carry the API path, column definitions and component info
	 *             needed to render list/detail/form pages. This method is
	 *             retained for backward compatibility with existing plugins.
	 */
	public function getResources(): array;

	/**
	 * Admin screens contributed by this extension.
	 *
	 * Each screen describes a route in the admin SPA, including its type
	 * (list/detail/form/custom), the API endpoint it calls (for generic
	 * screens), and — for custom screens — whether it ships a JS bundle
	 * or uses a core component.
	 *
	 * Plugins that do not implement this method should use
	 * {@see AdminExtensionTrait} to get an empty default.
	 *
	 * @return list<AdminScreen>
	 */
	public function getAdminScreens(): array;
}
