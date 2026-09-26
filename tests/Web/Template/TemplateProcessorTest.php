<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\TemplateResource;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Repository\Object\TemplateRepository;
use iikiti\CMS\State\Processor\TemplateProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TemplateProcessorTest extends TestCase
{
	private function operation(string $name): object
	{
		$op = $this->createStub(\ApiPlatform\Metadata\Operation::class);
		$op->method('getName')->willReturn($name);

		return $op;
	}

	private function templateEntity(string $title): Template
	{
		$t = new Template();
		$properties = (new \ReflectionClass(\iikiti\CMS\Entity\DbObject::class))->getProperty('properties');
		$properties->setValue($t, new ArrayCollection());
		$t->setProperty('title', $title);

		return $t;
	}

	public function testCreatePersistsAndMapsFields(): void
	{
		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::once())->method('persist')->with(self::isInstanceOf(Template::class));
		$em->expects(self::once())->method('flush');

		$repo = $this->createStub(TemplateRepository::class);
		$processor = new TemplateProcessor($repo, $em);

		$resource = new TemplateResource(
			title: 'Home',
			layout: 'base/default-block-editor-content.twig',
			regions: [['name' => 'main', 'role' => 'main']],
			assignments: [['rule' => 'site']],
		);

		$result = $processor->process($resource, $this->operation('admin_template_create'));

		self::assertSame('Home', $result->title);
		self::assertSame('base/default-block-editor-content.twig', $result->layout);
		self::assertSame([['name' => 'main', 'role' => 'main']], $result->regions);
	}

	public function testUpdateMapsAndFlushes(): void
	{
		$existing = $this->templateEntity('Old title');

		$repo = $this->createStub(TemplateRepository::class);
		$repo->method('find')->willReturn($existing);

		$em = $this->createMock(EntityManagerInterface::class);
		$em->expects(self::once())->method('flush');

		$processor = new TemplateProcessor($repo, $em);

		$resource = new TemplateResource(
			title: 'Default',
			layout: 'base/default-block-editor-content.twig',
		);

		$result = $processor->process($resource, $this->operation('admin_template_update'), ['id' => 26]);

		self::assertSame('Default', $result->title);
		self::assertSame('base/default-block-editor-content.twig', $result->layout);
	}

	public function testUpdateThrowsWhenTemplateNotFound(): void
	{
		$repo = $this->createStub(TemplateRepository::class);
		$repo->method('find')->willReturn(null);
		$em = $this->createStub(EntityManagerInterface::class);
		$processor = new TemplateProcessor($repo, $em);

		$this->expectException(NotFoundHttpException::class);

		$processor->process(
			new TemplateResource(title: 'Ghost'),
			$this->operation('admin_template_update'),
			['id' => 99],
		);
	}
}
