<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Application;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Resolves the `{applicationId}` path variable to an Application, or reports not-found. */
final readonly class FirewallApplicationLookup
{
	public function __construct(private EntityManagerInterface $entityManager)
	{
	}

	/** @param array<string,mixed> $uriVariables */
	public function require(array $uriVariables): Application
	{
		$id = $uriVariables['applicationId'] ?? null;
		if (!is_string($id) && !is_int($id)) {
			throw new NotFoundHttpException('Application not found.');
		}

		$application = $this->entityManager->getRepository(Application::class)->find($id);
		if (!$application instanceof Application) {
			throw new NotFoundHttpException('Application not found.');
		}

		return $application;
	}
}
