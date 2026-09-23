<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Web\BlockEditor\BlockType\BlockTypeRegistry;
use iikiti\CMS\Web\BlockEditor\BlockType\CoreBlockTypeProvider;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderContext;
use iikiti\CMS\Web\BlockEditor\Render\BlockRenderer;
use iikiti\CMS\Web\Template\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

final class TemplateRendererTest extends TestCase
{
	private function renderer(): TemplateRenderer
	{
		$layoutEnv = new Environment(new ArrayLoader([
			'layout.twig' => '<header>{{ region_header|raw }}</header><main id="main">{{ region_main|raw }}</main>',
		]), ['strict_variables' => false]);

		$blockTwig = new Environment(new FilesystemLoader(__DIR__.'/../../../templates/blocks'), [
			'strict_variables' => false,
		]);
		$blockRenderer = new BlockRenderer(new BlockTypeRegistry([new CoreBlockTypeProvider()]), $blockTwig);

		return new TemplateRenderer($blockRenderer, $layoutEnv);
	}

	/**
	 * @param list<array<string,mixed>> $regions
	 */
	private function template(array $regions, string $layout = 'layout.twig'): Template
	{
		$t = new Template();
		$i = (new \ReflectionClass(DbObject::class))->getProperty('properties');
		$i->setAccessible(true);
		$i->setValue($t, new ArrayCollection());
		$t->setLayout($layout);
		$t->setRegions($regions);

		return $t;
	}

	public function testRendersRegionsWhenMainIsPresent(): void
	{
		$template = $this->template([
			['id' => 'header', 'role' => 'header', 'name' => 'Header', 'allowed_types' => []],
			['id' => 'main', 'role' => 'main', 'name' => 'Main', 'allowed_types' => []],
		]);

		$html = $this->renderer()->render($template, new BlockRenderContext(editorMode: false), []);

		$this->assertStringContainsString('<main id="main">', $html);
	}

	public function testNoMainRegionShowsErrorInPublicMode(): void
	{
		$template = $this->template([
			['id' => 'header', 'role' => 'header', 'name' => 'Header', 'allowed_types' => []],
		]);

		$html = $this->renderer()->render($template, new BlockRenderContext(editorMode: false), []);

		$this->assertStringContainsString('main content area must be provided', $html);
	}

	public function testNoMainRegionShowsEditorErrorInEditMode(): void
	{
		$template = $this->template([
			['id' => 'sidebar', 'role' => 'sidebar', 'name' => 'Sidebar', 'allowed_types' => []],
		]);

		$html = $this->renderer()->render($template, new BlockRenderContext(editorMode: true), []);

		$this->assertStringContainsString('no_main_region', $html);
		$this->assertStringContainsString('main content area must be provided', $html);
	}
}
