<?php
namespace iikiti\CMS\Web\BlockEditor\Interfaces;



interface ComponentInterface {

    public function getContainerList(): array;

    public function getSettingsFields(): array;

}
