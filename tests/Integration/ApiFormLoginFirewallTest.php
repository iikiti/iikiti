<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\Object\ApplicationRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderResolver;
use iikiti\CMS\Service\ResponseHeaderStore;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiFormLoginFirewallTest extends WebTestCase
{
	public function testLoginFormSubmitsToTheApiOwnedFormLoginEndpoint(): void
	{
		$client = static::createClient();
		$sites = $this->createStub(SiteRepository::class);
		$sites->method('findByDomain')->willReturn([]);
		$applications = $this->createStub(ApplicationRepository::class);
		$applications->method('find')->willReturn(new Application());
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturnCallback(static fn (string $class) => Site::class === $class ? $sites : $applications);
		$policy = new ResponseHeaderPolicy();
		static::getContainer()->set(ResponseHeaderResolver::class, new ResponseHeaderResolver($entityManager, new ResponseHeaderStore($policy), $policy, 1));
		$crawler = $client->request('GET', '/login', ['siteHost' => 'iikiti.localhost']);
		self::assertResponseIsSuccessful();

		$form = $crawler->filter('form');
		self::assertSame('/api/auth/login', $form->attr('action'));
		self::assertSame('iikiti.localhost', $form->filter('input[name="_site_host"]')->attr('value'));
		self::assertNotSame('', (string) $form->filter('input[name="_csrf_token"]')->attr('value'));
	}
}
