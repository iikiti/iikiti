<?php

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Authentication\Mail\MfaCodeMailerInterface;
use iikiti\CMS\Security\AccessHandler;
use iikiti\CMS\Workflow\StepProvider\DynamicFormStepProvider;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use iikiti\CMS\Workflow\WorkflowFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Verifies the multi-factor services and routes are wired into the kernel.
 */
final class MfaWiringTest extends KernelTestCase
{
	public function testMfaRoutesAreRegistered(): void
	{
		self::bootKernel();

		$router = self::getContainer()->get('router');
		$this->assertInstanceOf(RouterInterface::class, $router);

		$routes = $router->getRouteCollection();
		$mfaRoute = $routes->get('mfa_challenge');
		$this->assertNotNull($mfaRoute);
		$this->assertSame('/mfa/challenge', $mfaRoute->getPath());
		$this->assertNotNull($routes->get('multi_step_form'));
	}

	public function testMfaServicesAreRegistered(): void
	{
		self::bootKernel();

		$container = self::getContainer();

		$this->assertInstanceOf(MfaStepProvider::class, $container->get(MfaStepProvider::class));
		$this->assertInstanceOf(DynamicFormStepProvider::class, $container->get(DynamicFormStepProvider::class));
		$this->assertInstanceOf(AccessHandler::class, $container->get(AccessHandler::class));
		$this->assertInstanceOf(WorkflowFactoryInterface::class, $container->get(WorkflowFactoryInterface::class));
		$this->assertInstanceOf(MfaCodeMailerInterface::class, $container->get(MfaCodeMailerInterface::class));
	}
}
