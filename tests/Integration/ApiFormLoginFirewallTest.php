<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiFormLoginFirewallTest extends WebTestCase
{
	public function testLoginFormSubmitsToTheApiOwnedFormLoginEndpoint(): void
	{
		$client = static::createClient();
		$crawler = $client->request('GET', '/login', ['siteHost' => 'iikiti.localhost']);
		self::assertResponseIsSuccessful();

		$form = $crawler->filter('form');
		self::assertSame('/api/auth/login', $form->attr('action'));
		self::assertSame('iikiti.localhost', $form->filter('input[name="_site_host"]')->attr('value'));
		self::assertNotSame('', (string) $form->filter('input[name="_csrf_token"]')->attr('value'));
	}
}
