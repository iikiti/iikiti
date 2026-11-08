<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Render;

use iikiti\CMS\Tests\Support\IconResolverFactory;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\WidgetFragmentPlanner;
use PHPUnit\Framework\TestCase;

final class WidgetFragmentPlannerTest extends TestCase
{
	private WidgetFragmentPlanner $planner;

	protected function setUp(): void
	{
		$this->planner = new WidgetFragmentPlanner(
			new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]),
		);
	}

	public function testPlansDirectChildrenByDefaultAndHonorsPerWidgetOverrides(): void
	{
		$tree = [[
			'id' => 'root',
			'type' => 'container',
			'children' => [
				['id' => 'default-child', 'type' => 'text'],
				['id' => 'async-child', 'type' => 'text', 'renderMode' => 'always'],
				[
					'id' => 'sync-parent',
					'type' => 'container',
					'renderMode' => 'never',
					'children' => [['id' => 'nested-async', 'type' => 'text', 'renderMode' => 'always']],
				],
			],
		]];

		$plan = $this->planner->plan([
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'tree' => $tree],
		], new BlockRenderContext(editorMode: false), str_repeat('a', 64), 10);

	self::assertSame(['root', 'async-child', 'nested-async'], $this->blockIds($plan));
	}

	public function testUsesStructuralIdentityForDuplicateBlockIds(): void
	{
		$tree = [[
			'id' => 'root',
			'type' => 'container',
			'children' => [
				['id' => 'duplicate', 'type' => 'text', 'renderMode' => 'always'],
				['id' => 'duplicate', 'type' => 'text', 'renderMode' => 'always'],
			],
		]];

		$plan = $this->planner->plan([
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'tree' => $tree],
		], new BlockRenderContext(editorMode: false), str_repeat('b', 64), 10);
		$descriptors = $plan->descriptors();

		self::assertCount(3, $descriptors);
		self::assertCount(3, array_unique(array_column($descriptors, 'id')));
		self::assertCount(3, array_unique(array_column($descriptors, 'slot')));
	}

	public function testQueryBlockRemainsAtomicAndFragmentBudgetFallsBackToInline(): void
	{
		$tree = [[
			'id' => 'root',
			'type' => 'container',
			'children' => [
				['id' => 'query', 'type' => 'query', 'children' => [
					['id' => 'query-child', 'type' => 'text', 'renderMode' => 'always'],
				]],
				['id' => 'limited', 'type' => 'text', 'renderMode' => 'always'],
				['id' => 'overflow', 'type' => 'text', 'renderMode' => 'always'],
			],
		]];

		$plan = $this->planner->plan([
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'tree' => $tree],
		], new BlockRenderContext(editorMode: false), str_repeat('c', 64), 2);

		self::assertSame(['root', 'limited'], $this->blockIds($plan));
	}

	/** @return list<string> */
	private function blockIds(\iikiti\CMS\Web\BlockEditor\Render\WidgetFragmentPlan $plan): array
	{
		return array_map(
			static fn (array $descriptor): string => (string) ($plan->entry($descriptor['id'])['node']['id'] ?? ''),
			$plan->descriptors(),
		);
	}
}
