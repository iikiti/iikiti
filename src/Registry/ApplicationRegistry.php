<?php

namespace iikiti\CMS\Registry;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Doctrine\Collections\ArrayCollection;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\Object\SiteRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app_registry')]
class ApplicationRegistry
{
	public function __construct(
		private EntityManagerInterface $em,
		private SiteRegistry $siteRegistry
	) {
	}

	public function getCurrent(): Application
	{
		$app = $this->__getCurrent();

		if (null === $app) {
			throw new \Exception('No application.');
		}

		return $app;
	}

	public function hasCurrentApplication(): bool
	{
		return null !== $this->__getCurrent();
	}

	private function __getCurrent(): ?Application
	{
		/** @var SiteRepository $siteRep */
		$siteRep = $this->em->getRepository(Site::class);

		return $siteRep->getApplicationBySite($this->siteRegistry::getCurrent());
	}

	public function getAllParents(): Collection
	{
		return (new ArrayCollection($this->siteRegistry::getAll()))->map(
			fn (Site $site): ?Application => $this->em->getRepository(Site::class)->
				getApplicationBySite($site)
		)->unique();
	}
}
