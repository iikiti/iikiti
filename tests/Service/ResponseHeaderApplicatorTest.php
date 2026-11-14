<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Service\ResponseHeaderApplicator;
use iikiti\CMS\Service\SecurityHeaderPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ResponseHeaderApplicatorTest extends TestCase
{
	public function testDefaultCspUsesSameNonceAsHtml(): void
	{
		$applicator = new ResponseHeaderApplicator(new SecurityHeaderPolicy('prod'), 'default');
		$response = new Response('<script>run()</script>', 200, ['Content-Type' => 'text/html']);
		$applicator->apply(Request::create('https://example.test/'), $response, [], 'default');
		$csp = (string) $response->headers->get('Content-Security-Policy');
		self::assertStringNotContainsString('__IIKITI_CSP_NONCE__', $csp);
		self::assertMatchesRegularExpression('/nonce-([A-Za-z0-9+\/=]+)/', $csp);
		preg_match('/nonce-([A-Za-z0-9+\/=]+)/', $csp, $matches);
		self::assertStringContainsString('nonce="'.$matches[1].'"', (string) $response->getContent());
	}

	public function testStreamedResponsesAreNotReplaced(): void
	{
		$applicator = new ResponseHeaderApplicator(new SecurityHeaderPolicy('prod'), 'default');
		$response = new StreamedResponse(static function (): void {}, 200, ['Content-Type' => 'text/html']);
		$applicator->apply(Request::create('https://example.test/'), $response, [], 'default');
		self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
	}

	public function testMergesSecurityDefaultsAndCustomRulesAndSubstitutesNonce(): void
	{
		$applicator = new ResponseHeaderApplicator(new SecurityHeaderPolicy('prod'), 'default');
		$request = Request::create('https://example.test/');
		$response = new Response('<script>run()</script>', 200, ['Content-Type' => 'text/html']);
		$applicator->apply($request, $response, [
			'content-security-policy' => ['name' => 'Content-Security-Policy', 'action' => 'set', 'value' => 'script-src __IIKITI_CSP_NONCE__'],
			'x-frame-options' => ['name' => 'X-Frame-Options', 'action' => 'remove'],
			'x-robots-tag' => ['name' => 'X-Robots-Tag', 'action' => 'set', 'value' => 'noindex'],
		], 'default');
		self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
		self::assertFalse($response->headers->has('X-Frame-Options'));
		self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
		self::assertMatchesRegularExpression('/script-src nonce-([A-Za-z0-9+\/=]+)/', (string) $response->headers->get('Content-Security-Policy'));
		preg_match('/script-src nonce-([A-Za-z0-9+\/=]+)/', (string) $response->headers->get('Content-Security-Policy'), $matches);
		self::assertStringContainsString('nonce="'.$matches[1].'"', (string) $response->getContent());
	}

	public function testSecurityModeOffDoesNotSuppressGeneralHeaders(): void
	{
		$applicator = new ResponseHeaderApplicator(new SecurityHeaderPolicy('prod'), 'off');
		$response = new Response('', 500, ['X-Frame-Options' => 'existing']);
		$applicator->apply(Request::create('http://example.test/'), $response, [
			'x-frame-options' => ['name' => 'X-Frame-Options', 'action' => 'remove'],
			'x-robots-tag' => ['name' => 'X-Robots-Tag', 'action' => 'set', 'value' => 'noindex'],
		], 'default');
		self::assertSame('existing', $response->headers->get('X-Frame-Options'));
		self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
	}
}
