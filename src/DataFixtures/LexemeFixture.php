<?php

namespace iikiti\CMS\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use iikiti\CMS\Entity\Object\Lexeme;

/**
 * LexemeFixture (fake data).
 */
class LexemeFixture extends Fixture
{
	public function load(ObjectManager $manager): void
	{
		static::generate_a($manager);
	}

	public static function generate_single(ObjectManager $manager): void
	{
		$lexeme = new Lexeme();
		$manager->persist($lexeme);

		// add more entries

		$manager->flush();
	}

	public static function generate_a(ObjectManager $manager): void
	{
	}
}
