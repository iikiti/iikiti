<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\Fragment;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Web\Fragment\FragmentContext;
use iikiti\CMS\Web\Fragment\FragmentPlanCollector;
use iikiti\CMS\Web\Fragment\FragmentProviderInterface;
use iikiti\CMS\Web\Fragment\FragmentProviderRegistry;
use iikiti\CMS\Web\Fragment\FragmentResult;
use iikiti\CMS\Web\Fragment\FragmentRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FragmentApiTest extends TestCase
{
	public function testRegistryResolvesUniqueNamespacedProviderIds(): void
	{
		$provider = $this->provider('sample-plugin.card');
		$registry = new FragmentProviderRegistry([$provider]);

		self::assertTrue($registry->has('sample-plugin.card'));
		self::assertSame($provider, $registry->get('sample-plugin.card'));
		self::assertFalse($registry->has('arbitrary-template.twig'));
	}

	public function testRegistryRejectsDuplicateIds(): void
	{
		$provider = $this->provider('sample-plugin.card');
		$this->expectException(\LogicException::class);
		new FragmentProviderRegistry([$provider, $this->provider('sample-plugin.card')]);
	}

	public function testRegistryRejectsUnnamespacedProviderIds(): void
	{
		$this->expectException(\LogicException::class);
		new FragmentProviderRegistry([$this->provider('not-namespaced')]);
	}

	public function testRuntimeRendersOnlyProvidersEnabledForTheCurrentSite(): void
	{
		$provider = $this->provider('sample-plugin.card');
		$runtime = new FragmentRuntime(new FragmentProviderRegistry([$provider]));
		$site = $this->createStub(Site::class);
		$site->method('getEnabledPlugins')->willReturn(['sample-plugin']);

		$result = $runtime->render('sample-plugin.card', new FragmentContext($site, 'en', ['title' => 'Hello']));

		self::assertSame('<article>Hello</article>', $result->html);
		self::assertSame([['id' => 'sample-plugin.card', 'css' => '.card{}']], $result->styles);
	}

	public function testRuntimeHidesProvidersDisabledForTheCurrentSite(): void
	{
		$runtime = new FragmentRuntime(new FragmentProviderRegistry([$this->provider('sample-plugin.card')]));
		$site = $this->createStub(Site::class);
		$site->method('getEnabledPlugins')->willReturn([]);

		$this->expectException(NotFoundHttpException::class);
		$runtime->render('sample-plugin.card', new FragmentContext($site, 'en'));
	}

	public function testPlanProducesServerSlotsAndEscapedBrowserDescriptors(): void
	{
		$plan = new FragmentPlanCollector();
		$serverSlot = $plan->declare('sample-plugin.card', ['title' => 'Server'], ['key' => 'server-card']);
		$browserSlot = $plan->declare('sample-plugin.card', ['title' => '<Browser>'], [
			'delivery' => 'browser',
			'key' => 'browser-card',
			'dependsOn' => ['server-card'],
		]);

		$descriptors = $plan->serverDescriptors();
		self::assertStringContainsString('data-iikiti-provider-slot', $serverSlot);
		self::assertStringContainsString('data-iikiti-fragment-descriptor', $browserSlot);
		self::assertStringNotContainsString('<Browser>', $browserSlot);
		self::assertCount(1, $descriptors);
		self::assertSame('server-card', $descriptors[0]['key']);
		self::assertSame(['title' => 'Server'], $descriptors[0]['params']);
	}

	public function testPlanRejectsUnknownDependenciesAndCycles(): void
	{
		$unknown = new FragmentPlanCollector();
		$unknown->declare('sample-plugin.card', [], ['key' => 'first', 'dependsOn' => ['missing']]);
		try {
			$unknown->serverDescriptors();
			self::fail('Expected unknown dependency validation to fail.');
		} catch (\InvalidArgumentException $exception) {
			self::assertStringContainsString('unknown key', $exception->getMessage());
		}

		$cycle = new FragmentPlanCollector();
		$cycle->declare('sample-plugin.card', [], ['key' => 'first', 'dependsOn' => ['second']]);
		$cycle->declare('sample-plugin.card', [], ['key' => 'second', 'dependsOn' => ['first']]);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('cycle');
		$cycle->serverDescriptors();
	}

	public function testServerPlacementCannotDependOnBrowserDelivery(): void
	{
		$plan = new FragmentPlanCollector();
		$plan->declare('sample-plugin.card', [], ['delivery' => 'browser', 'key' => 'browser']);
		$plan->declare('sample-plugin.card', [], ['key' => 'server', 'dependsOn' => ['browser']]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot depend on browser fragment');
		$plan->serverDescriptors();
	}

	private function provider(string $name): FragmentProviderInterface
	{
		return new class($name) implements FragmentProviderInterface {
			public function __construct(private readonly string $name)
			{
			}

			public function getName(): string
			{
				return $this->name;
			}

			public function render(FragmentContext $context): FragmentResult
			{
				$title = htmlspecialchars((string) ($context->params['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

				return new FragmentResult('<article>'.$title.'</article>', [['id' => $this->name, 'css' => '.card{}']]);
			}
		};
	}
}
