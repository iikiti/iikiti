<?php

namespace iikiti\CMS\Service;

class Configuration {



    public function __construct(protected object $json) {
        
    }

    public function getActiveExtensions(): array {
        return $this->json->extensions->active ?? [];
    }

}