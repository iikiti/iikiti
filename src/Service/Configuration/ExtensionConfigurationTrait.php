<?php

namespace iikiti\CMS\Service\Configuration;

use stdClass;

trait ExtensionConfigurationTrait {

    public function getExtensionData(): object {
        return $this->getJson()->extensions ?? new stdClass();
    }

    /**
     * @return array<string,mixed>
     */
    public function getActiveExtensions(): array {
        return $this->getExtensionData()->active ?? [];
    }

    public function getExtensionConfiguration(?string $extensionSlug): object {
        $extensions = $this->getExtensionData();
        $config = $extensions->configuration ?? new stdClass();

        if (isset($config->{$extensionSlug})) {
            return (object) $config->{$extensionSlug};
        }

        return new stdClass();
    }

}