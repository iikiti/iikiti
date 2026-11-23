<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Plugin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Doctrine\UnknownDbObjectClassGenerator;
use iikiti\CMS\Event\Listener\DynamicDiscriminatorMapListener;
use iikiti\CMS\Event\Subscriber\RequiresPluginSubscriber;
use iikiti\CMS\Plugin\Attribute\RequiresPlugin;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\GuestApiTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHardeningTest extends TestCase
{
	public function testUnknownDbObjectClassGeneratorRejectsInvalidDiscriminators(): void
	{
		$generator = new UnknownDbObjectClassGenerator(sys_get_temp_dir().'/iikiti-test-unknown-'.bin2hex(random_bytes(4)));

		foreach (['', "bad\nline", "nul\0byte", str_repeat('a', 129)] as $badDiscriminator) {
			try {
				$generator->ensureClassForDiscriminator($badDiscriminator);
				self::fail('Expected InvalidArgumentException for invalid discriminator.');
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testRequiresPluginSubscriberRunsEarlyAndEnforcesClassStringCallables(): void
	{
		$events = RequiresPluginSubscriber::getSubscribedEvents();
		self::assertGreaterThan(0, $events[KernelEvents::CONTROLLER][1]);

		$registry = new PluginRegistry();
		$registry->add(PluginManifest::fromArray([
			'name' => 'Opt',
			'slug' => 'opt-only',
			'namespace' => 'Acme\\Opt',
			'bundle_class' => 'Acme\\Opt\\OptBundle',
			'version' => '1.0.0',
			'iikiti_version' => '^1.0',
			'activation_mode' => PluginManifest::ACTIVATION_OPT_IN,
		]));
		$siteRegistry = new SiteRegistry(
			new RequestStack(),
			$this->createStub(ManagerRegistry::class),
			new GuestApiTokenManager('secret'),
			'api',
		);
		$subscriber = new RequiresPluginSubscriber(new PluginActivationChecker($registry, $siteRegistry));

		$controllerClass = new #[RequiresPlugin('opt-only')] class {
			public static function handle(): void
			{
			}
		};

		$event = new ControllerEvent(
			$this->createStub(HttpKernelInterface::class),
			[$controllerClass::class, 'handle'],
			Request::create('/'),
			HttpKernelInterface::MAIN_REQUEST,
		);

		$this->expectException(NotFoundHttpException::class);
		$subscriber->onKernelController($event);
	}

	public function testDynamicDiscriminatorMapListenerRollsBackSavepointWhenDiscoveryFailsInTransaction(): void
	{
		$connection = $this->createMock(Connection::class);
		$connection->method('isTransactionActive')->willReturn(true);
		$connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		$savepointsCreated = [];
		$savepointsRolledBack = [];
		$connection->expects(self::once())
			->method('createSavepoint')
			->willReturnCallback(static function (string $name) use (&$savepointsCreated): void {
				$savepointsCreated[] = $name;
			});
		$connection->expects(self::once())
			->method('fetchFirstColumn')
			->willThrowException(new \RuntimeException('missing column'));
		$connection->expects(self::never())->method('releaseSavepoint');
		$connection->expects(self::once())
			->method('rollbackSavepoint')
			->willReturnCallback(static function (string $name) use (&$savepointsRolledBack): void {
				$savepointsRolledBack[] = $name;
			});

		$listener = new DynamicDiscriminatorMapListener($this->createStub(ManagerRegistry::class));
		$method = new \ReflectionMethod($listener, 'fetchFirstColumnSafely');
		$result = $method->invoke($listener, $connection, 'SELECT discriminators FROM plugin_registry');

		self::assertSame([], $result);
		self::assertCount(1, $savepointsCreated);
		self::assertSame($savepointsCreated, $savepointsRolledBack);
	}
}
