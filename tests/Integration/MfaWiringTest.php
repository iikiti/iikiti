<?php

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Authentication\Mail\MfaCodeMailerInterface;
use iikiti\CMS\Authentication\Mfa\MfaFactorProviderRegistry;
use iikiti\CMS\Authentication\Mfa\MfaWorkflowDefinitionResolver;
use iikiti\CMS\Security\AccessHandler;
use iikiti\CMS\Workflow\Definition\WorkflowInteractiveDefinitionResolver;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use iikiti\CMS\Workflow\WorkflowFactoryInterface;
use iikiti\CMS\Workflow\WorkflowGraphFactoryInterface;
use iikiti\CMS\Workflow\WorkflowGraphStepFactory;
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
		$this->assertNotNull($routes->get('multi_step_form_db'));
	}

	public function testMfaServicesAreRegistered(): void
	{
		self::bootKernel();

		$container = self::getContainer();

		$this->assertInstanceOf(MfaStepProvider::class, $container->get(MfaStepProvider::class));
		$this->assertInstanceOf(MfaFactorProviderRegistry::class, $container->get(MfaFactorProviderRegistry::class));
		$this->assertInstanceOf(MfaWorkflowDefinitionResolver::class, $container->get(MfaWorkflowDefinitionResolver::class));
		$this->assertInstanceOf(WorkflowInteractiveDefinitionResolver::class, $container->get(WorkflowInteractiveDefinitionResolver::class));
		$this->assertInstanceOf(WorkflowGraphStepFactory::class, $container->get(WorkflowGraphStepFactory::class));
		$this->assertInstanceOf(AccessHandler::class, $container->get(AccessHandler::class));
		$this->assertInstanceOf(WorkflowFactoryInterface::class, $container->get(WorkflowFactoryInterface::class));
		$this->assertInstanceOf(WorkflowGraphFactoryInterface::class, $container->get(WorkflowGraphFactoryInterface::class));
		$this->assertInstanceOf(MfaCodeMailerInterface::class, $container->get(MfaCodeMailerInterface::class));
	}
}
