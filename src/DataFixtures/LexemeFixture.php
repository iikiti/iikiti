<?php

use iikiti\CMS\Entity\Object\Lexeme;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class LexemeFixture extends Fixture
{
    public function load(ObjectManager $manager)
    {
        static::generate_a($manager);
    }

    public static function generate_single(ObjectManager $manager)
    {
        $lexeme = new Lexeme();
        $manager->persist($lexeme);

        // add more entries

        $manager->flush();
    }

    public static function generate_a(ObjectManager $manager)
    {

    }
}