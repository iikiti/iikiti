<?php

namespace iikiti\CMS\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * App fixture (fake data).
 */
class AppFixtures extends Fixture
{
	public function load(ObjectManager $manager): void
	{
		// $product = new Product();
		// $manager->persist($product);

		$manager->flush();
	}
}
