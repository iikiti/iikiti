<?php

namespace iikiti\CMS;

use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

/**
 *
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        registerBundles as kernelRegisterBundles;
    }

    public function boot(): void
    {
        parent::boot();
    }

    public function registerBundles(): iterable
    {
        yield from $this->kernelRegisterBundles();
    }

}
