<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor\Workflow;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\TemplateRepository;
use iikiti\CMS\Web\BlockEditor\Workflow\DraftPublishWorkflow;
use PHPUnit\Framework\TestCase;

final class DraftPublishWorkflowTest extends TestCase
{
	private function template(int $id = 1): Template
	{
		$t = new Template();
		$ref = (new \ReflectionClass(DbObject::class))->getProperty('properties');
		$ref->setAccessible(true);
		$ref->setValue($t, new ArrayCollection());
		$idProp = (new \ReflectionClass(DbObject::class))->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($t, $id);

		return $t;
	}

	private function workflow(Template $template): DraftPublishWorkflow
	{
		$repo = $this->createStub(TemplateRepository::class);
		$repo->method('find')->willReturn($template);
		$em = $this->createStub(EntityManagerInterface::class);
		$em->method('getRepository')->willReturn($repo);

		return new DraftPublishWorkflow($em);
	}

	private function user(): User
	{
		return $this->createStub(User::class);
	}

	public function testSaveWritesDraftAndBumpsVersion(): void
	{
		$template = $this->template();
		$workflow = $this->workflow($template);

		$result = $workflow->save('template', 1, ['main' => [['type' => 'text']]], null, $this->user());

		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['version']);
		$this->assertSame(['main' => [['type' => 'text']]], $template->getBlocksDraft());
		$this->assertSame(1, $template->getDraftVersion());
	}

	public function testSaveConflictsOnStaleVersion(): void
	{
		$template = $this->template();
		$template->setProperty('draft_version', 3);
		$workflow = $this->workflow($template);

		$result = $workflow->save('template', 1, ['main' => []], '2', $this->user());

		$this->assertFalse($result['ok']);
		$this->assertTrue($result['conflict']);
		$this->assertSame(3, $result['version']);
	}

	public function testSaveSucceedsWithMatchingVersion(): void
	{
		$template = $this->template();
		$template->setProperty('draft_version', 3);
		$workflow = $this->workflow($template);

		$result = $workflow->save('template', 1, ['main' => []], '3', $this->user());

		$this->assertTrue($result['ok']);
		$this->assertSame(4, $result['version']);
	}

	public function testPublishCopiesDraftToPublished(): void
	{
		$template = $this->template();
		$template->setProperty('blocks_draft', ['main' => [['type' => 'text']]]);
		$workflow = $this->workflow($template);

		$result = $workflow->publish('template', 1, $this->user());

		$this->assertTrue($result['ok']);
		$this->assertSame(['main' => [['type' => 'text']]], $template->getBlocks());
	}
}
