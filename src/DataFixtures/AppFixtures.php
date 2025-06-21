<?php

namespace iikiti\CMS\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Override;

/**
 * App fixture (fake data).
 */
class AppFixtures extends Fixture
{
	#[Override]
	public function load(ObjectManager $manager): void
	{
		// $product = new Product();
		// $manager->persist($product);

		$manager->flush();
	}
}
