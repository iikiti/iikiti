<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use iikiti\CMS\Admin\CoreAdminExtension;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminScreen;
use PHPUnit\Framework\TestCase;

final class TemplatesAdminScreenTest extends TestCase
{
	private CoreAdminExtension $extension;

	protected function setUp(): void
	{
		$this->extension = new CoreAdminExtension();
	}

	private function screen(string $path): ?AdminScreen
	{
		foreach ($this->extension->getAdminScreens() as $screen) {
			if ($screen->path === $path) {
				return $screen;
			}
		}

		return null;
	}

	public function testTemplatesListScreenNavigatesToEditForm(): void
	{
		$list = $this->screen('/admin/templates');

		self::assertNotNull($list);
		self::assertSame('list', $list->type);
		self::assertSame('/api/admin/templates', $list->apiPath);
		self::assertSame('/admin/templates/edit', $list->config['editPath'] ?? null);
	}

	public function testTemplatesEditAndCreateFormScreensAreRegistered(): void
	{
		$edit = $this->screen('/admin/templates/edit');
		$create = $this->screen('/admin/templates/new');

		self::assertNotNull($edit);
		self::assertSame('form', $edit->type);
		self::assertSame($edit->apiPath, $edit->config['apiPath'] ?? $edit->apiPath);

		self::assertNotNull($create);
		self::assertSame('form', $create->type);

		$editFields = $edit->config['fields'] ?? [];
		self::assertContains(['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true], $editFields);
	}

	public function testTemplatesMenuItemIsPresent(): void
	{
		$paths = array_map(static fn (AdminMenuItem $item): string => $item->path, $this->extension->getMenuItems());

		self::assertContains('/admin/templates', $paths);
	}
}
