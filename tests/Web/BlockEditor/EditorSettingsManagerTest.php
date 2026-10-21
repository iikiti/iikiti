<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use PHPUnit\Framework\TestCase;

final class EditorSettingsManagerTest extends TestCase
{
	public function testStatePrefersDraftAndPreservesPublishedSettingsWhenNoDraftExists(): void
	{
		$page = $this->object(Page::class);
		$page->setProperty('settings', ['title' => 'Published', 'plugin' => ['keep' => true]]);
		$page->setProperty('settings_draft', ['title' => 'Draft', 'plugin' => ['keep' => true]]);
		$page->setProperty('settings_version', 4);
		$manager = new EditorSettingsManager($this->createStub(EntityManagerInterface::class));

		$this->assertSame([
			'values' => ['title' => 'Draft', 'plugin' => ['keep' => true]],
			'version' => 4,
			'hasDraft' => true,
		], $manager->state($page));

		$page->setProperty('settings_draft', null);
		$this->assertSame(['title' => 'Published', 'plugin' => ['keep' => true]], $manager->state($page)['values']);
	}

	public function testFindTargetOnlyAcceptsPageAndTemplateTypes(): void
	{
		$page = $this->object(Page::class);
		$template = $this->object(Template::class);
		$repository = $this->createStub(EntityRepository::class);
		$repository->method('find')->willReturnCallback(fn (int $id): ?DbObject => 10 === $id ? $page : $template);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturn($repository);
		$manager = new EditorSettingsManager($entityManager);

		$this->assertSame($page, $manager->findTarget('page', 10));
		$this->assertSame($template, $manager->findTarget('template', 11));
		$this->assertNull($manager->findTarget('template', 10));
		$this->assertNull($manager->findTarget('object', 10));
	}

	public function testSaveRejectsStaleSettingsVersionWithoutReplacingDraft(): void
	{
		$page = $this->object(Page::class);
		$page->setProperty('settings_draft', ['title' => 'Existing']);
		$page->setProperty('settings_version', 3);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$manager = new EditorSettingsManager($entityManager);

		$result = $manager->save($page, ['title' => 'Stale'], '2');

		$this->assertSame(['ok' => false, 'version' => 3, 'conflict' => true], $result);
		$this->assertSame(['title' => 'Existing'], $page->getProperties()->get('settings_draft')?->getValue());
	}

	public function testSaveAndPublishMoveSettingsThroughDraftLifecycle(): void
	{
		$template = $this->object(Template::class);
		$template->setSettings(['legacy' => 'preserved']);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$manager = new EditorSettingsManager($entityManager);

		$saved = $manager->save($template, ['legacy' => 'updated', 'seoTitle' => 'Home'], '0');
		$this->assertSame(['ok' => true, 'version' => 1, 'conflict' => false], $saved);
		$this->assertSame(['legacy' => 'preserved'], $template->getSettings());
		$this->assertSame(['legacy' => 'updated', 'seoTitle' => 'Home'], $manager->state($template)['values']);

		$published = $manager->publish($template, '1');
		$this->assertSame(['ok' => true, 'version' => 2, 'conflict' => false, 'hasDraft' => false], $published);
		$this->assertSame(['legacy' => 'updated', 'seoTitle' => 'Home'], $template->getSettings());
		$this->assertNull($template->getProperties()->get('settings_draft'));
		$this->assertFalse($manager->state($template)['hasDraft']);
	}

	private function object(string $class): DbObject
	{
		$object = new $class();
		$properties = (new \ReflectionClass(DbObject::class))->getProperty('properties');
		$properties->setValue($object, new ArrayCollection());

		return $object;
	}
}
