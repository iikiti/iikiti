<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use iikiti\CMS\Service\ResponseHeaderPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResponseHeaderPolicyTest extends TestCase
{
	public function testPrecedenceAndConditions(): void
	{
		$engine = new ResponseHeaderPolicy();
		$app = $engine->validateRules([
			['name' => 'X-Test', 'action' => 'set', 'value' => 'default'],
			['name' => 'X-Test', 'action' => 'set', 'value' => 'required', 'inheritance' => 'required', 'conditions' => ['field' => 'status', 'operator' => 'equals', 'value' => 500]],
		], true);
		$site = $engine->validateRules([
			['name' => 'x-test', 'action' => 'remove', 'priority' => 5, 'conditions' => ['all' => [
				['field' => 'method', 'operator' => 'equals', 'value' => 'GET'],
				['not' => ['field' => 'path', 'operator' => 'regex', 'value' => '^/admin']],
			]]],
			['name' => 'X-Test', 'action' => 'set', 'value' => 'later', 'priority' => 5],
		], false);
		$request = Request::create('/page');
		self::assertSame('remove', $engine->resolve($app, $site, $request, new Response('', 200))['x-test']['action']);
		self::assertSame('required', $engine->resolve($app, $site, $request, new Response('', 500))['x-test']['value']);
	}

	public function testRegexErrorsCannotBeNegated(): void
	{
		$engine = new ResponseHeaderPolicy();
		$rules = $engine->validateRules([['name' => 'X-Test', 'action' => 'set', 'value' => 'yes', 'conditions' => ['not' => ['field' => 'path', 'operator' => 'regex', 'value' => '^/(a+)+$']]]], false);
		$request = Request::create('/'.str_repeat('a', 4000).'b');
		self::assertSame([], $engine->resolve([], $rules, $request, new Response()));
	}

	/** @param array<array-key,mixed> $rules */
	#[DataProvider('invalidRules')]
	public function testInvalidRulesAreRejected(array $rules): void
	{
		$this->expectException(\InvalidArgumentException::class);
		(new ResponseHeaderPolicy())->validateRules($rules, false);
	}

	/** @return iterable<array{array<array-key,mixed>}> */
	public static function invalidRules(): iterable
	{
		yield [[['name' => 'Set-Cookie', 'action' => 'remove']]];
		yield [[['name' => 'X-Test', 'action' => 'set', 'value' => "bad\r\nInjected: yes"]]];
		yield [[['name' => 'X-Test', 'action' => 'remove', 'conditions' => ['field' => 'header', 'name' => 'Authorization', 'operator' => 'present']]]];
		yield [[['name' => 'X-Test', 'action' => 'remove', 'conditions' => ['field' => 'path', 'operator' => 'regex', 'value' => '(']]]];
		yield [[['name' => 'X-Test', 'action' => 'remove', 'conditions' => ['field' => 'path', 'operator' => 'regex', 'value' => '(?R)']]]];
		yield [[['name' => 'X-Test', 'action' => 'remove', 'inheritance' => 'required']]];
		yield [[['name' => 'X-Test', 'action' => 'remove', 'conditions' => ['field' => 'header', 'name' => 'X-Custom-Token', 'operator' => 'present']]]];
	}

	public function testRequestPredicatesAndSiteOverride(): void
	{
		$engine = new ResponseHeaderPolicy();
		$app = [['name' => 'X-Test', 'action' => 'set', 'value' => 'app', 'priority' => 100]];
		$site = [['name' => 'X-Test', 'action' => 'set', 'value' => 'site', 'conditions' => ['all' => [
			['field' => 'scheme', 'operator' => 'equals', 'value' => 'https'],
			['field' => 'header', 'name' => 'X-Region', 'operator' => 'present'],
			['any' => [['field' => 'header', 'name' => 'x-region', 'operator' => 'equals', 'value' => 'eu'], ['field' => 'header', 'name' => 'x-region', 'operator' => 'regex', 'value' => '^us']]],
		]]]];
		$request = Request::create('https://example.test/', 'GET', server: ['HTTP_X_REGION' => 'us-west']);
		self::assertSame('site', $engine->resolve($app, $site, $request, new Response())['x-test']['value']);
		$request->headers->remove('x-region');
		self::assertSame('app', $engine->resolve($app, $site, $request, new Response())['x-test']['value']);
	}

	public function testPathPrefixAndStatusClass(): void
	{
		$engine = new ResponseHeaderPolicy();
		$rules = [['name' => 'X-Test', 'action' => 'set', 'value' => 'yes', 'conditions' => ['all' => [
			['field' => 'path', 'operator' => 'prefix', 'value' => '/private'],
			['field' => 'status', 'operator' => 'class', 'value' => 4],
		]]]];
		$request = Request::create('/private/page');
		self::assertSame('yes', $engine->resolve([], $rules, $request, new Response('', 404))['x-test']['value']);
		self::assertSame([], $engine->resolve([], $rules, $request, new Response('', 200)));
	}
}
