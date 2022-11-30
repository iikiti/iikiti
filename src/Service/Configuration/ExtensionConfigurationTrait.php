<?php

namespace iikiti\CMS\Service\Configuration;

use stdClass;

trait ExtensionConfigurationTrait {

    public function getExtensionData(): object {
        return $this->getJson()->extensions ?? new stdClass();
    }

    public function getActiveExtensions(): array {
        return $this->getExtensionData()->active ?? [];
    }

    public function getExtensionConfiguration(?string $extensionSlug): object {
        return (object)
            $this->getExtensionData()->configuration->{$extensionSlug} ??
            new stdClass();
    }

}