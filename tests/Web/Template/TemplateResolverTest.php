<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Template;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Web\Template\Rule\ObjectTypeRule;
use iikiti\CMS\Web\Template\Rule\SiteRule;
use iikiti\CMS\Web\Template\TemplateResolutionContext;
use iikiti\CMS\Web\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

final class TemplateResolverTest extends TestCase
{
	/**
	 * @param list<array<string,mixed>> $assignments
	 */
	private function template(string $title, array $assignments): Template
	{
		$t = new Template();
		$i = (new \ReflectionClass(\iikiti\CMS\Entity\DbObject::class))->getProperty('properties');
		$i->setValue($t, new \Doctrine\Common\Collections\ArrayCollection());
		$t->setProperty('title', $title);
		$t->setProperty('assignments', $assignments);

		return $t;
	}

	/**
	 * @param list<Template> $templates The templates the DQL `WHERE t.site = :site`
	 *                                  returns (the mock ignores the DQL body).
	 */
	private function em(array $templates): EntityManagerInterface
	{
		$query = $this->createStub(Query::class);
		$query->method('getResult')->willReturn($templates);
		$query->method('setParameter')->willReturnSelf();

		$repo = $this->createStub(\Doctrine\ORM\EntityRepository::class);
		$repo->method('findAll')->willReturn($templates);

		$em = $this->createStub(EntityManagerInterface::class);
		$em->method('createQuery')->willReturn($query);
		$em->method('getRepository')->willReturn($repo);

		return $em;
	}

	public function testResolvesByObjectTypeRule(): void
	{
		$site = $this->createStub(\iikiti\CMS\Entity\Object\Site::class);
		$site->method('getId')->willReturn(1);

		$resolver = new TemplateResolver($this->em([
			$this->template('Site template', [['rule' => 'site', 'config' => ['site_id' => 1], 'priority' => 5]]),
			$this->template('Page template', [['rule' => 'object_type', 'config' => ['type' => 'Page'], 'priority' => 50]]),
		]), [new SiteRule(), new ObjectTypeRule()]);

		$resolved = $resolver->resolve(new TemplateResolutionContext(site: $site, objectType: 'Page'));

		$this->assertNotNull($resolved);
		$this->assertSame('Page template', $resolved->getTitle());
	}

	public function testHighestPriorityAssignmentWins(): void
	{
		$resolver = new TemplateResolver($this->em([
			$this->template('Low', [['rule' => 'object_type', 'config' => ['type' => 'Page'], 'priority' => 1]]),
			$this->template('High', [['rule' => 'object_type', 'config' => ['type' => 'Page'], 'priority' => 100]]),
		]), [new ObjectTypeRule()]);

		$this->assertSame('High', $resolver->resolve(
			new TemplateResolutionContext(objectType: 'Page')
		)?->getTitle());
	}

	public function testReturnsNullWhenNoAssignmentMatches(): void
	{
		$resolver = new TemplateResolver($this->em([
			$this->template('Page only', [['rule' => 'object_type', 'config' => ['type' => 'Page'], 'priority' => 10]]),
		]), [new ObjectTypeRule()]);

		$this->assertNull($resolver->resolve(new TemplateResolutionContext(objectType: 'Post')));
	}
}
