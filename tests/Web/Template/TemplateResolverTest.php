<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ObjectRepository;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Web\Template\Rule\ObjectTypeRule;
use iikiti\CMS\Web\Template\Rule\SiteRule;
use iikiti\CMS\Web\Template\TemplateResolutionContext;
use iikiti\CMS\Web\Template\TemplateResolver;
use iikiti\CMS\Web\Template\TemplateRuleInterface;
use PHPUnit\Framework\TestCase;

final class TemplateResolverTest extends TestCase
{
	/**
	 * @param list<array<string,mixed>> $assignments
	 */
	private function template(string $title, array $assignments): Template
	{
		$t = new Template();
		$i = (new \ReflectionClass(DbObject::class))->getProperty('properties');
		$i->setAccessible(true);
		$i->setValue($t, new ArrayCollection());
		$t->setProperty('title', $title);
		$t->setProperty('assignments', $assignments);

		return $t;
	}

	/**
	 * @param list<Template> $templates
	 *
	 * @return ObjectRepository<Template>
	 */
	private function repo(array $templates): ObjectRepository
	{
		$repo = $this->createStub(ObjectRepository::class);
		$repo->method('findBy')->willReturn($templates);
		$repo->method('findAll')->willReturn($templates);

		return $repo;
	}

	public function testResolvesByObjectTypeRule(): void
	{
		$resolver = new TemplateResolver($this->repo([
			$this->template('Site template', [['rule' => 'site', 'config' => ['site_id' => 1], 'priority' => 5]]),
			$this->template('Page template', [['rule' => 'object_type', 'config' => ['type' => 'Page'], 'priority' => 50]]),
		]), [new SiteRule(), new ObjectTypeRule()]);

		$resolved = $resolver->resolve(new TemplateResolutionContext(objectType: 'Page'));

		$this->assertNotNull($resolved);
		$this->assertSame('Page template', $resolved->getTitle());
	}

	public function testHighestPriorityAssignmentWins(): void
	{
		$resolver = new TemplateResolver($this->repo([
			$this->template('Low', [['rule' => 'object_type', 'config' => ['type' => 'Page'], 'priority' => 1]]),
			$this->template('High', [['rule' => 'object_type', 'config' => ['type' => 'Page'], 'priority' => 100]]),
		]), [new ObjectTypeRule()]);

		$this->assertSame('High', $resolver->resolve(
			new TemplateResolutionContext(objectType: 'Page')
		)?->getTitle());
	}

	public function testPluginRuleTypeIsMatched(): void
	{
		$pluginRule = new class implements TemplateRuleInterface {
			public function getName(): string
			{
				return 'tag';
			}

			public function matches(array $config, TemplateResolutionContext $context): bool
			{
				return ($config['tag'] ?? '') === 'landing';
			}
		};

		$resolver = new TemplateResolver($this->repo([
			$this->template('By tag', [['rule' => 'tag', 'config' => ['tag' => 'landing'], 'priority' => 10]]),
		]), [$pluginRule]);

		$this->assertSame('By tag', $resolver->resolve(
			new TemplateResolutionContext(objectType: 'Page')
		)?->getTitle());
	}

	public function testReturnsNullWhenNoAssignmentMatches(): void
	{
		$resolver = new TemplateResolver($this->repo([
			$this->template('Page only', [['rule' => 'object_type', 'config' => ['type' => 'Page'], 'priority' => 10]]),
		]), [new ObjectTypeRule()]);

		$this->assertNull($resolver->resolve(new TemplateResolutionContext(objectType: 'Post')));
	}
}
