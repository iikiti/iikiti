<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Page;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\CMS\Web\BlockEditor\PageRendering;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use Psr\Container\ContainerInterface;
use Closure;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Homepage controller.
 */
#[AsController]
class HomeController extends AppController
{
	/** @param Closure():PageRendering $pageRendering */
	public function __construct(
		Security $security,
		ContainerInterface $container,
		#[AutowireServiceClosure(PageRendering::class)]
		private readonly Closure $pageRendering,
		private readonly PublicPageApiProxy $apiProxy,
		private readonly MfaAuthenticationGate $mfaGate,
		#[Autowire('%application_role%')]
		private readonly string $applicationRole,
	) {
		parent::__construct($security, $container);
	}

	#[Route('/', name: 'home', priority: 0, format: 'html')]
	public function index(Request $request): Response
	{
		if ('consumer' === $this->applicationRole) {
			return $this->apiProxy->home($request);
		}

		$user = $this->security->getUser();
		if ($user instanceof User && !$this->mfaGate->allowsFullyAuthenticatedUser()) {
			return new RedirectResponse('/api/auth/mfa/challenge');
		}

		return ($this->pageRendering)()->render($request, home: true);
	}
}
