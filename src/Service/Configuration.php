<?php

namespace iikiti\CMS\Service;

use iikiti\CMS\Service\Configuration as Config;

class Configuration {

    use Config\ExtensionConfigurationTrait;

    public function __construct(protected readonly object $json) {
        
    }

    public function getJson(): object {
        return $this->json;
    }

}