<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Subscriber;

use iikiti\CMS\Plugin\Attribute\RequiresPlugin;
use iikiti\CMS\Plugin\PluginActivationChecker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces {@see RequiresPlugin} attributes on controllers and API Platform
 * resources before execution.
 */
final readonly class RequiresPluginSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private PluginActivationChecker $activationChecker,
	) {
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::CONTROLLER => ['onKernelController', 10],
		];
	}

	public function onKernelController(ControllerEvent $event): void
	{
		$requirements = [];

		$resourceClass = $event->getRequest()->attributes->get('_api_resource_class');
		if (is_string($resourceClass) && class_exists($resourceClass)) {
			foreach ((new \ReflectionClass($resourceClass))->getAttributes(RequiresPlugin::class) as $attr) {
				$requirements[] = $attr->newInstance();
			}
		}

		$controller = $event->getController();
		[$target, $methodName] = is_array($controller)
			? [$controller[0], $controller[1]]
			: (is_object($controller) && method_exists($controller, '__invoke') ? [$controller, '__invoke'] : [null, null]);
		if (null !== $target && is_string($methodName) && (is_object($target) || class_exists($target))) {
			$classRef = new \ReflectionClass($target);
			foreach ($classRef->getAttributes(RequiresPlugin::class) as $attr) {
				$requirements[] = $attr->newInstance();
			}
			if ($classRef->hasMethod($methodName)) {
				foreach ($classRef->getMethod($methodName)->getAttributes(RequiresPlugin::class) as $attr) {
					$requirements[] = $attr->newInstance();
				}
			}
		}

		foreach ($requirements as $requirement) {
			if (!$this->activationChecker->isActive($requirement->slug, $requirement->scope)) {
				if (403 === $requirement->statusCode) {
					throw new AccessDeniedHttpException(sprintf('Plugin "%s" is not active.', $requirement->slug));
				}

				throw new NotFoundHttpException(sprintf('Plugin "%s" is not active.', $requirement->slug));
			}
		}
	}
}
