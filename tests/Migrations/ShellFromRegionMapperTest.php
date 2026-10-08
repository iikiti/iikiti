<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Migrations;

use iikiti\CMS\Web\Template\ShellFromRegionMapper;
use PHPUnit\Framework\TestCase;

final class ShellFromRegionMapperTest extends TestCase
{
	public function testNonMainRegionWithBlocksBecomesShell(): void
	{
		$shells = ShellFromRegionMapper::shellsFromRegions(
			[['id' => 'header', 'role' => 'header', 'name' => 'Header']],
			['header' => [['type' => 'text', 'id' => 'b1']]],
		);

		$this->assertCount(1, $shells);
		$this->assertSame('header', $shells[0]['role']);
		$this->assertSame('Header', $shells[0]['name']);
		$this->assertSame(0, $shells[0]['priority']);
		$this->assertSame([['type' => 'text', 'id' => 'b1']], $shells[0]['blocks']);
	}

	public function testMapsSidebarRoleToAsideAndSkipsMain(): void
	{
		$shells = ShellFromRegionMapper::shellsFromRegions(
			[
				['id' => 'main', 'role' => 'main', 'name' => 'Main'],
				['id' => 'aside-left', 'role' => 'sidebar', 'name' => 'Left sidebar'],
			],
			['main' => [['type' => 'text']], 'aside-left' => [['type' => 'text']]],
		);

		$this->assertCount(1, $shells);
		$this->assertSame('aside', $shells[0]['role']);
	}

	public function testEmptyRegionProducesNoShell(): void
	{
		$shells = ShellFromRegionMapper::shellsFromRegions(
			[['id' => 'footer', 'role' => 'footer', 'name' => 'Footer']],
			['footer' => []],
		);

		$this->assertSame([], $shells);
	}

	public function testPriorityFollowsRegionOrder(): void
	{
		$shells = ShellFromRegionMapper::shellsFromRegions(
			[
				['id' => 'header', 'role' => 'header', 'name' => 'Header'],
				['id' => 'footer', 'role' => 'footer', 'name' => 'Footer'],
			],
			['header' => [['type' => 'text']], 'footer' => [['type' => 'text']]],
		);

		$this->assertSame([0, 1], array_column($shells, 'priority'));
	}
}
