<?php

namespace iikiti\CMS\Registry;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Application;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AutoconfigureTag('app_registry')]
class ApplicationRegistry
{
	protected static \SplStack $appStack;
	protected static bool $initialized = false;

	public function __construct(
		protected RequestStack $requestStack,
		protected ManagerRegistry $registry,
	) {
		if (static::$initialized) {
			return;
		} elseif (isset(self::$appStack)) {
			static::$initialized = true;

			return;
		}
		static::$appStack = new \SplStack();
		$this->populate();
		static::$initialized = true;
	}

	private function populate(): void
	{
		$request = $this->requestStack->getCurrentRequest();
		if (null === $request) {
			return;
		}
		$appRep = $this->registry->getManager()->getRepository(Application::class);
		$apps = $appRep->findByDomain($request->getHost());
		if (empty($apps)) {
			throw new NotFoundHttpException('Application does not exist for current domain.');
		}
		foreach ($apps as $app) {
			self::pushApplication($app);
		}
	}

	public function getCurrentApplication(): Application
	{
		return static::count() < 1 ? new Application() : static::$appStack->top();
	}

	public function pushApplication(Application $app): void
	{
		static::$appStack->push($app);
	}

	public function popApplication(): ?Application
	{
		return static::count() < 1 ? null : static::$appStack->pop();
	}

	public function count(): int
	{
		return static::$appStack->count();
	}
}
