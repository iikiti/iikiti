<?php
namespace iikiti\CMS\Web\BlockEditor\Interfaces;

use Doctrine\Persistence\ManagerRegistry;

interface ComponentInterface {

    public function getContainerList(): array;

    public function getSettingsFields(): array;

}
