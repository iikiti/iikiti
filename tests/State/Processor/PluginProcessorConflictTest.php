<?php

namespace iikiti\CMS\Tests\State\Processor;

use ApiPlatform\Metadata\Post;
use iikiti\CMS\ApiResource\PluginOperation;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Plugin\Exception\PluginActionConflictException;
use iikiti\CMS\Plugin\PluginDownloader;
use iikiti\CMS\Plugin\PluginLoader;
use iikiti\CMS\Plugin\PluginManager;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginValidator;
use iikiti\CMS\State\Processor\PluginProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PluginProcessorConflictTest extends TestCase
{
	public function testConflictFailureMapsToHttp409WithDetails(): void
	{
		$manifest = $this->manifest();
		$conflicts = [['site' => '4', 'plugin' => 'optimizer-b', 'action' => 'content.transform.minify.html']];
		$manager = $this->createStub(PluginManager::class);
		$manager->method('getInstalled')->willReturn([
			$manifest->slug => ['manifest' => $manifest, 'path' => '', 'installInfo' => null],
		]);
		$manager->method('getActivationConflicts')->willReturn($conflicts);
		$manager->method('activateForAllSites')->willThrowException(new PluginActionConflictException('Plugin action conflicts prevent activation.', $conflicts));
		$processor = $this->processor($manager, $this->createStub(AuditRecorder::class));

		try {
			$processor->process(new PluginOperation(slug: $manifest->slug), new Post(name: 'admin_plugin_enable'));
			self::fail('Expected the API processor to reject the activation.');
		} catch (ConflictHttpException $exception) {
			self::assertSame(409, $exception->getStatusCode());
			self::assertSame(json_encode($conflicts, JSON_THROW_ON_ERROR), $exception->getHeaders()['X-Plugin-Action-Conflicts']);
			self::assertInstanceOf(PluginActionConflictException::class, $exception->getPrevious());
			self::assertSame($conflicts, $exception->getPrevious()->getConflicts());
		}
	}

	public function testForcedConflictOverrideIsAudited(): void
	{
		$manifest = $this->manifest();
		$conflicts = [['site' => '4', 'plugin' => 'optimizer-b', 'action' => 'content.transform.minify.html']];
		$manager = $this->createMock(PluginManager::class);
		$manager->method('getInstalled')->willReturn([
			$manifest->slug => ['manifest' => $manifest, 'path' => '', 'installInfo' => null],
		]);
		$manager->method('getActivationConflicts')->willReturn($conflicts);
		$manager->expects(self::once())->method('activateForAllSites')->with($manifest->slug, true)->willReturn(1);
		$audit = $this->createMock(AuditRecorder::class);
		$audit->expects(self::once())->method('record')->with(
			self::stringContains('despite declared action conflicts'),
			'enabled_plugin',
			'Plugin',
			null,
			null,
			null,
			['plugin' => $manifest->slug, 'conflicts' => $conflicts],
		);
		$processor = $this->processor($manager, $audit);

		$operation = new PluginOperation(slug: $manifest->slug, forceConflicts: true);
		$result = $processor->process($operation, new Post(name: 'admin_plugin_enable'));

		self::assertTrue($result->success);
	}

	private function processor(PluginManager $manager, AuditRecorder $audit): PluginProcessor
	{
		return new PluginProcessor(
			$manager,
			$this->createStub(PluginDownloader::class),
			$this->createStub(PluginValidator::class),
			$this->createStub(PluginLoader::class),
			$audit,
		);
	}

	private function manifest(): PluginManifest
	{
		return new PluginManifest('Optimizer A', 'optimizer-a', 'Acme\\Optimizer', 'Acme\\Optimizer\\Bundle', '1.0.0', '^1.0');
	}
}
