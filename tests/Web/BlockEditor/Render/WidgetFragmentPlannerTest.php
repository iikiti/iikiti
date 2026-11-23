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

	public function testPlansDirectChildrenByDefaultWhenMultipleSiblingsExistAndHonorsPerWidgetOverrides(): void
	{
		$tree = [
			[
				'id' => 'root-a',
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
			],
			[
				'id' => 'root-b',
				'type' => 'container',
				'children' => [],
			],
		];

		$plan = $this->planner->plan([
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'tree' => $tree],
		], new BlockRenderContext(editorMode: false), str_repeat('a', 64), 10);

		self::assertSame(['root-a', 'async-child', 'nested-async', 'root-b'], $this->blockIds($plan));
	}

	public function testSingleTopLevelDefaultWidgetStaysInlineUnlessCachedOrAlways(): void
	{
		$singleDefault = [[
			'id' => 'only-root',
			'type' => 'container',
			'children' => [['id' => 'child', 'type' => 'text']],
		]];
		$planDefault = $this->planner->plan([
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'tree' => $singleDefault],
		], new BlockRenderContext(editorMode: false), str_repeat('1', 64), 10);
		self::assertSame([], $this->blockIds($planDefault));

		$singleCached = [[
			'id' => 'cached-root',
			'type' => 'container',
			'cacheMode' => 'global',
			'children' => [],
		]];
		$planCached = $this->planner->plan([
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'tree' => $singleCached],
		], new BlockRenderContext(editorMode: false), str_repeat('2', 64), 10);
		self::assertSame(['cached-root'], $this->blockIds($planCached));

		$singleAlways = [[
			'id' => 'always-root',
			'type' => 'container',
			'renderMode' => 'always',
			'children' => [],
		]];
		$planAlways = $this->planner->plan([
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'tree' => $singleAlways],
		], new BlockRenderContext(editorMode: false), str_repeat('3', 64), 10);
		self::assertSame(['always-root'], $this->blockIds($planAlways));
	}

	public function testUsesStructuralIdentityForDuplicateBlockIds(): void
	{
		$tree = [[
			'id' => 'root',
			'type' => 'container',
			'renderMode' => 'always',
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
			'renderMode' => 'always',
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

	public function testWidgetIdsAreStablePerDedicatedKeyAndChangeWhenItRotates(): void
	{
		$roots = [[
			'kind' => 'region',
			'id' => 'main',
			'role' => 'main',
			'tree' => [[
				'id' => 'root',
				'type' => 'container',
				'children' => [['id' => 'widget', 'type' => 'text', 'renderMode' => 'always']],
			]],
		]];
		$context = new BlockRenderContext(editorMode: false);
		$revision = str_repeat('r', 64);
		$registry = new BlockTypeRegistry([new CoreBlockTypeProvider(IconResolverFactory::bundledOnly())]);
		$first = (new WidgetFragmentPlanner($registry, str_repeat('a', 32)))->plan($roots, $context, $revision, 10);
		$again = (new WidgetFragmentPlanner($registry, str_repeat('a', 32)))->plan($roots, $context, $revision, 10);
		$rotated = (new WidgetFragmentPlanner($registry, str_repeat('b', 32)))->plan($roots, $context, $revision, 10);

		self::assertSame(array_column($first->descriptors(), 'id'), array_column($again->descriptors(), 'id'));
		self::assertNotSame(array_column($first->descriptors(), 'id'), array_column($rotated->descriptors(), 'id'));
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
